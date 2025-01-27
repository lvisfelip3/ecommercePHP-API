<?php 
require_once __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

define('API_KEY', $_ENV['FLOW_API_KEY']);
define('SECRET_KEY', $_ENV['FLOW_SECRET_KEY']);
define('FLOW_API_URL', $_ENV['FLOW_API_URL']);
define('SITE_API_URL', $_ENV['SITE_API_URL']);
define('BASE_URL', $_ENV['BASE_URL']);

class PaymentService {
    private $pdo;
    private $flowConfig;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->flowConfig = [
            'apiKey' => API_KEY,
            'secretKey' => SECRET_KEY,
            'apiUrl' => FLOW_API_URL,
            'confirmationUrl' => SITE_API_URL . "flow/confirmation.php",
            'returnUrl' => BASE_URL . "pedidos/checkout/"
        ];
    }

    public function initiatePayment($orderData, $verifiedProducts) {
        try {
            $this->pdo->beginTransaction();

            $saleRef = uniqid();

            $totalAmount = $this->calculateTotal($verifiedProducts);

            $flowResponse = $this->createFlowPayment($totalAmount, $saleRef, $orderData);
            
            $this->storeOrderData($saleRef, $orderData, $flowResponse);

            $this->pdo->commit();
            
            return [
                'orderRef' => $saleRef,
                'responseFlow' => $flowResponse,
                'urlFlow' => $flowResponse['url'] . "?token=" . $flowResponse['token']
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function calculateTotal($products) {
        $totalAmount = 0;

        foreach ($products as $item) {
            if (isset($item['precio'], $item['quantity'])) {
                $totalAmount += $item['precio'] * $item['quantity'];
            }
        }

        return $totalAmount;
    }

    private function storeOrderData($saleRef, $orderData, $flowResponse) {
        $query = "INSERT INTO ordenes_pendientes_flow (sale_ref, order_data, token) 
                    VALUES (:sale_ref, :order_data, :token)";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':sale_ref' => $saleRef,
            ':order_data' => json_encode($orderData),
            ':token' => $flowResponse['token']
        ]);
    }
    
    public function verifyProducts($cartProducts) {
        $verifiedProducts = [];

        foreach ($cartProducts as $item) {
            $productId = $item['product']['id'];
            $quantity = $item['quantity'];

            $query = "SELECT precio, stock FROM productos WHERE id = :product_id";

            $stmt = $this->pdo->prepare($query);
            $stmt->execute([':product_id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("Producto no encontrado con ID: " . $productId);
            }

            if ($product['stock'] < $quantity) {
                throw new Exception("Stock insuficiente para el producto con ID: " . $productId);
            }

            $verifiedProducts[] = [
                'id' => $productId,
                'precio' => $product['precio'],
                'quantity' => $quantity
            ];
        }

        return $verifiedProducts;
    }
    
    public function getTransactionStatus($token) {
        try {
            $params = [
                "apiKey" => $this->flowConfig['apiKey'],
                "token" => $token
            ];
            
            // Generar firma
            $keys = array_keys($params);
            sort($keys);
            
            $toSign = "";
            foreach($keys as $key) {
                $toSign .= $key . $params[$key];
            }
            $signature = hash_hmac('sha256', $toSign, $this->flowConfig['secretKey']);
            
            // Construir URL con los parámetros
            $url = $this->flowConfig['apiUrl'] . 'payment/getStatusExtended';
            $params["s"] = $signature;
            $url .= "?" . http_build_query($params);
            
            // Inicializar curl para GET
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("Error en la llamada a Flow: " . $error);
            }
            
            curl_close($ch);
            
            $statusData = json_decode($response, true);
            if (!$statusData) {
                throw new Exception("Error decodificando respuesta de Flow");
            }
            
            $this->handleFlowApiResponse($httpCode);
            
            return $statusData;
            
        } catch (Exception $e) {
            throw $e;
        }
    }


    public function processConfirmation($statusData) {
        try {
            $this->pdo->beginTransaction();
            if (isset($statusData['lastError']) && !empty($statusData['lastError']['code'])) {
                throw new Exception(
                    json_encode([
                        'code' => $statusData['lastError']['code'],
                        'message' => $statusData['lastError']['message'],
                        'mediaCode' => $statusData['lastError']['medioCode']
                    ])
                );
            }
            // Verificar el estado
            switch ($statusData['status']) {
                case 1:
                    throw new Exception("Pago pendiente");
                case 3:
                    throw new Exception("Pago rechazado");
                case 4:
                    throw new Exception("Pago anulado");
                case 5:
                    throw new Exception("Pago revertido");
                case 2:
                    // Continuar con el proceso - pago exitoso
                    break;
                default:
                    throw new Exception("Estado de pago desconocido");
            }
            
            // Obtener el commerceOrder desde los datos
            $saleRef = $statusData['commerceOrder'];
            $orderData = $this->retrieveOrderData($saleRef);
            
            if (!$orderData) {
                throw new Exception("Datos de la orden no encontrados para ref: " . $saleRef);
            }
            
            // Verificar si la orden ya existe
            if ($this->orderExists($saleRef)) {
                $this->pdo->commit();
                return [
                    'success' => true,
                    'message' => 'Orden ya procesada anteriormente'
                ];
            }
            
            $clientId = $this->checkClient($orderData);
            
            $direccion_id = $orderData['direccion_id'] != 0 && isset($orderData['direccion_id']) ? $orderData['direccion_id'] : null;

            $addressId = $this->createAddress($clientId, $orderData, $direccion_id);
            $totalAmount = $this->updateStock($orderData['products']);
            
            $saleId = $this->createSale($clientId, $addressId, $totalAmount, $saleRef);
            $this->addProductsToSale($saleId, $orderData['products']);

            $submethod = $statusData['paymentData']['media'] ?? null;
            $payment_type = $statusData['paymentData']['mediaType'] ?? null;
            $last4numbers = $statusData['paymentData']['cardLast4Numbers'] ?? null;
            $payed = $statusData['paymentData']['amount'] ?? null;

            $this->createPayment($saleId, $totalAmount, $submethod, $payment_type, $last4numbers, $payed);

            $this->createShipping($saleId);

            $this->cleanupPendingOrder($saleRef);
            
            $this->pdo->commit();
            return [
                'success' => true,
                'status' => $statusData['status'],
                'message' => 'Pago procesado exitosamente',
                'saleId' => $saleId
            ];
        } catch (Exception $e) {
            error_log($e->getMessage());
            $this->pdo->rollBack();
            $errorMessage = json_decode($e->getMessage(), true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Es un error estructurado de Flow
                return [
                    'success' => false,
                    'flowError' => $errorMessage
                ];
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function createFlowPayment($totalAmount, $saleRef, $orderData) {

        $totalAmountint = intval($totalAmount);
        if ($totalAmount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'El carrito está vacío']);
            exit();
        }
        
        $params = array(
            "apiKey" => $this->flowConfig['apiKey'],
            "commerceOrder" => $saleRef,
            "subject" => "Venta de Artículos",
            "currency" => "CLP",
            "amount" => $totalAmountint,
            "email" => $orderData['email'], 
            "urlConfirmation" => $this->flowConfig['confirmationUrl'],
            "urlReturn" => $this->flowConfig['returnUrl'] . $saleRef,
            "optional" => json_encode([
                "Referencia de Venta" => $saleRef,
                "Email Comprador" => $orderData['email'],
                "Rut" => $orderData['rut']
            ])
        );
        
        $signature = $this->generateFlowSignature($params);
        $params["s"] = $signature;
        
        $response = $this->makeFlowApiCall($this->flowConfig['apiUrl'] . 'payment/create', $params);
        
        return json_decode($response, true);
    }

    
    private function generateFlowSignature($params) {
        $keys = array_keys($params);
        sort($keys);
        
        $toSign = "";
        foreach($keys as $key) {
            $toSign .= $key . $params[$key];
        }
        
        return hash_hmac('sha256', $toSign, $this->flowConfig['secretKey']);
    }

    private function makeFlowApiCall($url, $params) {
        $ch = curl_init();
    
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Configurar headers específicos
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        
        if($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Error en la llamada a Flow: " . $error);
        }
        
        curl_close($ch);
        
        if ($httpCode === 400) {
            $responseData = json_decode($response, true);
            if (isset($responseData['message'])) {
                http_response_code(400);
                echo json_encode(['error' => $responseData['message']]);
                exit;
            }
        }
        
        $this->handleFlowApiResponse($httpCode);
        
        return $response;
    }

    private function handleFlowApiResponse($httpCode) {
        $errorMessages = [
            301 => 'Error de redirección. Por favor, usa la URL con www.',
            302 => 'Error de redirección. Por favor, usa la URL con www.',
            400 => 'Error en los parámetros enviados a Flow',
            401 => 'Error de autenticación con Flow. Verifica tus credenciales',
            404 => 'El endpoint de Flow no fue encontrado',
            500 => 'Error interno del servidor de Flow'
        ];

        if ($httpCode !== 200 && isset($errorMessages[$httpCode])) {
            throw new Exception($errorMessages[$httpCode]);
        } elseif ($httpCode !== 200) {
            throw new Exception('Error inesperado en Flow. HTTP_CODE: ' . $httpCode);
        }
    }
    
    private function orderExists($saleRef) {
        $query = "SELECT id FROM ventas WHERE referencia = :saleRef";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':saleRef', $saleRef);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? true : false;
    }
    
    private function checkClient($data)
    {
        $query = "SELECT id, usuario_id FROM clientes WHERE rut = :rut";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':rut', $data['rut']);
        $stmt->execute();
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            if($client['usuario_id'] == null && !empty($data['usuario_id'])) {
                $this->addUserIdToClient($data);
            } else {
                $this->updateEmail($client['id'], $data['email']);
            }
            return $client['id'];
        }
        return $this->createClient($data);
    }

    private function updateEmail($client_id, $email) {
        $query = "UPDATE clientes SET email = :email WHERE id = :client_id";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':client_id', $client_id);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
    }

    private function createClient($data)
    {
        $query="INSERT INTO clientes (nombre, usuario_id, apellido, telefono, email, estado, rut)
                VALUES (:nombre, :usuario_id, :apellido, :telefono, :email, 1, :rut)";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':nombre' => $data['nombre'],
            ':usuario_id' => $data['usuario_id'] ?? null,
            ':apellido' => $data['apellido'],
            ':telefono' => $data['telefono'],
            ':email' => $data['email'],
            ':rut' => $data['rut'],
        ]);
    
        return $this->pdo->lastInsertId();
    }
    
    private function addUserIdToClient($data) {
        $query = "UPDATE clientes SET usuario_id = :usuario_id, email = :email WHERE rut = :rut";
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':rut', $data['rut']);
        $stmt->bindParam(':usuario_id', $data['usuario_id']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->execute();
    }
    
    private function createSale($clientId, $addressId, $totalAmount, $saleRef)
    {
        $query="INSERT INTO ventas (cliente_id, direccion_id, total, estado, referencia)
                VALUES (:cliente_id, :direccion_id, :monto_total, 2, :referencia)";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':cliente_id' => $clientId,
            ':direccion_id' => $addressId,
            ':monto_total' => $totalAmount,
            ':referencia' => $saleRef
        ]);
    
        return $this->pdo->lastInsertId();
    }
    
    private function addProductsToSale($saleId, $data)
    {
        $query="INSERT INTO venta_productos (venta_id, producto_id, cantidad, precio) 
                VALUES (:venta_id, :producto_id, :cantidad, :precio_unitario)";
        $stmt = $this->pdo->prepare($query);
    
        foreach ($data as $item) {
            $stmt->execute([
                ':venta_id' => $saleId,
                ':producto_id' => $item['product']['id'],
                ':cantidad' => $item['quantity'],
                ':precio_unitario' => $item['product']['precio']
            ]);
        }
    }
    
    private function createAddress($clientId, $data, $addressId)
    {
        if ($addressId) {
            return $addressId;
        }
        $query="INSERT INTO direcciones (cliente_id, direccion, comuna_id, ciudad_id, estado, depto)
                VALUES (:client_id, :direccion, :comuna_id, :ciudad_id, 1, :depto)";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([
            ':client_id' => $clientId,
            ':direccion' => $data['direccion'],
            ':comuna_id' => $data['comuna'],
            ':ciudad_id' => $data['ciudad'],
            ':depto' => $data['depto'] ?? null,
        ]);
    
        return $this->pdo->lastInsertId();
    }
    
    private function updateStock($data)
    {
        $query = "SELECT stock FROM productos WHERE id = :product_id";
        $updateQuery = "UPDATE productos SET stock = stock - :quantity WHERE id = :product_id";
    
        $stmtSelect = $this->pdo->prepare($query);
        $stmtUpdate = $this->pdo->prepare($updateQuery);
    
        $totalAmount = 0;
    
        foreach ($data as $item) {
            if (isset($item['product']['id'], $item['quantity'])) {
                $stmtSelect->execute([':product_id' => $item['product']['id']]);
                $product = $stmtSelect->fetch();
    
                if (!$product || $product['stock'] < $item['quantity']) {
                    throw new Exception("Stock insuficiente para el producto ID " . $item['product']['id']);
                }
    
                $stmtUpdate->execute([
                    ':quantity' => $item['quantity'],
                    ':product_id' => $item['product']['id'],
                ]);
    
                $totalAmount += $item['product']['precio'] * $item['quantity'];
            }
        }
    
        return $totalAmount;
    }
    
    private function createPayment($saleId, $totalAmount, $submethod, $payment_type, $last4numbers, $payed)
    {
        $query="INSERT INTO pagos (venta_id, monto, metodo_pago, submetodo_pago, referencia, estado, tipo_pago, tarjeta_ultimos_numeros, debio_pagar)
                VALUES (:venta_id, :monto, :metodo_pago, :submetod, :referencia, 1, :payment_type, :last4numbers, :payed)";
        $stmt = $this->pdo->prepare($query);
        $reference = uniqid("PAY_");
    
        $stmt->execute([
            ':venta_id' => $saleId,
            ':monto' => $payed,
            ':metodo_pago' => 'Flow',
            ':referencia' => $reference,
            ':submetod' => $submethod,
            ':payment_type' => $payment_type,
            ':last4numbers' => $last4numbers,
            ':payed' => $totalAmount
        ]);
    
        return $this->pdo->lastInsertId();
    }
    
    private function createShipping($saleId)
    {
        $query="INSERT INTO envios (venta_id, estado)
                VALUES (:venta_id, 3)";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':venta_id' => $saleId]);
    }

    private function retrieveOrderData($saleRef) {
        $query = "SELECT order_data FROM ordenes_pendientes_flow WHERE sale_ref = :sale_ref";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':sale_ref' => $saleRef]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $orderData = json_decode($result['order_data'], true);
        return $orderData;
    }

    private function cleanupPendingOrder($saleRef) {
        $query = "UPDATE ordenes_pendientes_flow SET order_data = null WHERE sale_ref = :sale_ref;";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':sale_ref' => $saleRef]);
    }
    
    public function handleError($statusData) {
        try {
            
            switch ($statusData['status']) {
                case 1:
                    return [
                        'success' => false,
                        'status' => 1,
                        'message' => 'Pago pendiente'
                    ];
                    break;
                case 3:
                    return [
                        'success' => false,
                        'status' => 3,
                        'message' => 'Pago rechazado'
                    ];
                    break;
                case 4:
                    return [
                        'success' => false,
                        'status' => 4,
                        'message' => 'Pago cancelado'
                    ];
                    break;
                case 5:
                    return [
                        'success' => false,
                        'status' => 5,
                        'message' => 'Pago revertido'
                    ];
                    break;
                case 2:
                    return [
                        'success' => true,
                        'status' => 2,
                        'message' => 'Pago exitoso'
                    ];
                    break;
                default:
                    return [
                        'success' => false,
                        'message' => 'Estado de pago desconocido'
                    ];
            }
        } catch (Exception $e) {
            $errorMessage = json_decode($e->getMessage(), true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Es un error estructurado de Flow
                return [
                    'success' => false,
                    'flowError' => $errorMessage
                ];
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>