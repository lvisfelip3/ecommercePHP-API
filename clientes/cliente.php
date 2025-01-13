<?php
require '../config.php';
require '../cors.php';
require_once '../auth/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('?', $_SERVER['REQUEST_URI'], 2);
$request_path = explode('/', trim($request_uri[0], '/'));

switch ($method) {
    case 'GET':
        handleGetRequest($pdo);
        break;

    case 'POST':
        handlePostRequest($pdo);
        break;

    case 'PUT':
        $auth = validateToken();
        if ($auth && $auth->rol == 'admin') {
            handlePutRequest($pdo);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado']);
        }
        break;

    case 'DELETE':
        $auth = validateToken();
        if ($auth && $auth->rol == 'admin') {
            handleDeleteRequest($pdo);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado', 'auth' => $auth]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
        break;
}

function handleGetRequest($pdo)
{
    if (isset($_GET['id'])) {
        getClientById($pdo, intval($_GET['id']));
    } else {
        getClients($pdo);
    }
}

function handlePostRequest($pdo)
{
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['rut'], $data['direccion'], $data['method'])) {
        addClientForPayment($pdo);
    } else {
        addClient($pdo);
    }
}

function handlePutRequest($pdo)
{
    updateClient($pdo);
}

function handleDeleteRequest($pdo)
{
    if (isset($_GET['id'])) {
        deleteClient($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'ID no proporcionado']);
    }
}

function getClients($pdo)
{
    try {
        $query = "SELECT * FROM clientes WHERE estado = 1 OR estado = 0";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($clientes);
    } catch (Exception $e) {
        logError("Error al obtener clientes: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getClientById($pdo, $id)
{
    try {
        $query = "SELECT * FROM clientes WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $clientes = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($clientes) {
            echo json_encode($clientes);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Cliente no encontrada']);
        }
    } catch (Exception $e) {
        logError("Error al obtener clientes: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addClient($pdo)
{
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        $nombre = isset($data['nombre']) ? $data['nombre'] : '';
        $apellido = isset($data['apellido']) ? $data['apellido'] : '';
        $telefono = isset($data['telefono']) ? $data['telefono'] : '';
        $email = isset($data['email']) ? $data['email'] : '';
        $rut = isset($data['rut']) ? $data['rut'] : 'no rut';
        $usuario_id = isset($data['usuario_id']) ? $data['usuario_id'] : null;

        if (!empty($nombre)  && !empty($email)) {
            $query = "INSERT INTO clientes (nombre,usuario_id,apellido, telefono, email, estado, rut) VALUES (:nombre, :usuario_id, :apellido, :telefono, :email,1, :rut)";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':apellido', $apellido);
            $stmt->bindParam(':telefono', $telefono);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':rut', $rut);
            $stmt->bindParam(':usuario_id', $usuario_id);
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(['message' => 'Cliente creado']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al crear el cliente']);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'message' => 'Datos incompletos',
                'data' => $data
            ]);
        }
    } catch (Exception $e) {
        logError("Error al crear cliente: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addClientForPayment($pdo)
{
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        validateData($data);

        $pdo->beginTransaction();

        $clientId = checkClient($pdo, $data);
        $addressId = createAddress($pdo, $clientId, $data);
        $totalAmount = updateStock($pdo, $data['products']);

        $saleData = createSale($pdo, $clientId, $addressId, $totalAmount);

        addProductsToSale($pdo, $saleData['id'], $data['products']);

        createPayment($pdo, $saleData['id'], $totalAmount, $data['method']);

        createShipping($pdo, $saleData['id']);

        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'orderRef' => $saleData['reference'],
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno: ' . $e->getMessage()]);
    }
}

function validateData($data)
{
    $requiredFields = ['nombre', 'apellido', 'rut', 'method', 'comuna', 'ciudad', 'direccion', 'products'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            throw new Exception("El campo $field es requerido.");
        }
    }
}

function checkClient($pdo, $data)
{
    $query = "SELECT id, usuario_id FROM clientes WHERE rut = :rut";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':rut', $data['rut']);
    $stmt->execute();
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client) {
        if($client['usuario_id'] == null) {
            addUserIdToClient($pdo, $data);
        }
        return $client['id'];
    }
    return createClient($pdo, $data);
}

function addUserIdToClient($pdo, $data) {
    $query = "UPDATE clientes SET usuario_id = :usuario_id WHERE rut = :rut";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':rut', $data['rut']);
    $stmt->bindParam(':usuario_id', $data['usuario_id']);
    $stmt->execute();
}

function createSale($pdo, $clientId, $addressId, $totalAmount)
{
    $query="INSERT INTO ventas (cliente_id, direccion_id, total, estado, referencia)
            VALUES (:cliente_id, :direccion_id, :monto_total, 2, :referencia)";
    $stmt = $pdo->prepare($query);
    $reference = uniqid();
    $stmt->execute([
        ':cliente_id' => $clientId,
        ':direccion_id' => $addressId,
        ':monto_total' => $totalAmount,
        ':referencia' => $reference
    ]);

    return [
        'reference' => $reference,
        'id' => $pdo->lastInsertId()
    ];
}

function addProductsToSale($pdo, $saleId, $data)
{
    $query="INSERT INTO venta_productos (venta_id, producto_id, cantidad, precio) 
            VALUES (:venta_id, :producto_id, :cantidad, :precio_unitario)";
    $stmt = $pdo->prepare($query);

    foreach ($data as $item) {
        $stmt->execute([
            ':venta_id' => $saleId,
            ':producto_id' => $item['product']['id'],
            ':cantidad' => $item['quantity'],
            ':precio_unitario' => $item['product']['precio']
        ]);
    }
}

function createClient($pdo, $data)
{
    $query="INSERT INTO clientes (nombre, usuario_id, apellido, telefono, email, estado, rut)
            VALUES (:nombre, :usuario_id, :apellido, :telefono, :email, 1, :rut)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':nombre' => $data['nombre'],
        ':usuario_id' => $data['usuario_id'] ?? null,
        ':apellido' => $data['apellido'],
        ':telefono' => $data['telefono'],
        ':email' => $data['email'],
        ':rut' => $data['rut'],
    ]);

    return $pdo->lastInsertId();
}

function createAddress($pdo, $clientId, $data)
{
    $query="INSERT INTO direcciones (cliente_id, direccion, comuna_id, ciudad_id, estado, depto)
            VALUES (:client_id, :direccion, :comuna_id, :ciudad_id, 1, :depto)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':client_id' => $clientId,
        ':direccion' => $data['direccion'],
        ':comuna_id' => $data['comuna'],
        ':ciudad_id' => $data['ciudad'],
        ':depto' => $data['depto'] ?? null,
    ]);

    return $pdo->lastInsertId();
}

function updateStock($pdo, $data)
{
    $query = "SELECT stock FROM productos WHERE id = :product_id";
    $updateQuery = "UPDATE productos SET stock = stock - :quantity WHERE id = :product_id";

    $stmtSelect = $pdo->prepare($query);
    $stmtUpdate = $pdo->prepare($updateQuery);

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

function createPayment($pdo, $saleId, $totalAmount, $paymentMethod)
{
    $query="INSERT INTO pagos (venta_id, monto, metodo_pago, referencia, estado)
            VALUES (:venta_id, :monto, :metodo_pago, :referencia, 2)";
    $stmt = $pdo->prepare($query);
    $reference = uniqid("PAY_");

    $stmt->execute([
        ':venta_id' => $saleId,
        ':monto' => $totalAmount,
        ':metodo_pago' => $paymentMethod,
        ':referencia' => $reference,
    ]);

    return $pdo->lastInsertId();
}

function createShipping($pdo, $saleId)
{
    $query="INSERT INTO envios (venta_id, estado)
            VALUES (:venta_id, 3)";
    $stmt = $pdo->prepare($query);
    $stmt->execute([':venta_id' => $saleId]);
}



function updateClient($pdo)
{
    try {
        $putData = json_decode(file_get_contents('php://input'), true);
        $id = isset($putData['id']) ? $putData['id'] : 0;
        $nombre = isset($putData['nombre']) ? $putData['nombre'] : '';
        $apellido = isset($putData['apellido']) ? $putData['apellido'] : '';
        $telefono = isset($putData['telefono']) ? $putData['telefono'] : '';
        $email = isset($putData['email']) ? $putData['email'] : '';
        $rut = isset($putData['rut']) ? $putData['rut'] : 'no rut';
        $usuario_id = isset($putData['usuario_id']) ? $putData['usuario_id'] : null;
        $estado = isset($putData['estado']) ? $putData['estado'] : '';

        if (!empty($id) && !empty($nombre) && !empty($estado) && !empty($email)) {
            $query = "UPDATE clientes SET nombre = :nombre, usuario_id = :usuario_id, apellido = :apellido, telefono = :telefono, email = :email, estado = :estado, rut = :rut WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':apellido', $apellido);
            $stmt->bindParam(':telefono', $telefono);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':estado', $estado);
            $stmt->bindParam(':rut', $rut);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Cliente actualizado']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al actualizar el cliente']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Datos incompletos']);
        }
    } catch (Exception $e) {
        logError("Error al actualizar comuna: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function deleteClient($pdo, $id)
{
    try {
        $query = "UPDATE clientes SET estado = 2 WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'Cliente eliminado']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Error al eliminar el cliente']);
        }
    } catch (Exception $e) {
        logError("Error al eliminar ciudad: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function logError($message)
{
    $file = '../logs/errors.log';
    $current = file_get_contents($file);
    $current .= "[" . date('d-m-Y H:i:s') . "] " . $message . "\n";
    file_put_contents($file, $current);
}
