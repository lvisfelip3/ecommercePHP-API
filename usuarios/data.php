<?php
require '../config.php';
require '../cors.php';
require '../auth/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('?', $_SERVER['REQUEST_URI'], 2);
$request_path = explode('/', trim($request_uri[0], '/'));

switch ($method) {
    case 'GET':
        handleGetRequest($pdo);
        break;
    case 'POST':
        $auth = validateToken();
        if ($auth) {
            handlePostRequest($pdo);
        }
        break;
    case 'DELETE': 
        $auth = validateToken();
        if ($auth) {
            handleDeleteRequest($pdo);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
        break;
}

function handleGetRequest($pdo)
{
    if(isset($_GET['action']) && $_GET['action'] === 'getAddress'){
        getAddressByClientId($pdo, intval($_GET['id']));
    } else {
        getUserOrdersById($pdo, intval($_GET['id']));
    }
}

function handlePostRequest($pdo)
{
    if(isset($_GET['action']) && $_GET['action'] === 'createAddress') {
        createAddressById($pdo, intval($_GET['id']));
    } else {
        setUserPersonalData($pdo);
    }
}

function handleDeleteRequest($pdo)
{
    try {
        $query = "UPDATE direcciones SET estado = 2 WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $_GET['id']);
        $stmt->execute();
        http_response_code(200);
        echo json_encode(['message' => 'Dirección eliminada con éxito']);
    } catch (Exception $e) {
        logError("Error al eliminar usuario: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getUserOrdersById($pdo, $id)
{
    try {
        $query = "
        SELECT v.id AS id,
            v.creado_en AS fecha,
            d.direccion AS direccion,
            d.depto AS depto,
            comunas.nombre AS comuna,
            ciudades.nombre AS ciudad,
            e.estado AS estado_envio,
            p.estado AS estado_pago,
            v.total AS total,
            v.referencia AS referencia
        FROM clientes c
        INNER JOIN ventas v ON c.id = v.cliente_id
        INNER JOIN direcciones d ON v.direccion_id = d.id
        INNER JOIN comunas ON d.comuna_id = comunas.id
        INNER JOIN ciudades ON d.ciudad_id = ciudades.id
        INNER JOIN envios e ON v.id = e.venta_id
        INNER JOIN pagos p ON v.id = p.venta_id
        WHERE c.usuario_id = :id
        ";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $usuario = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($usuario as $order) {

            $products = products($pdo, $id, $order['id']);

            $result[] = [
                'id' => $order['id'],
                'venta' => [
                    'fecha' => $order['fecha'],
                    'estado_envio' => $order['estado_envio'],
                    'estado_pago' => $order['estado_pago'],
                    'total' => $order['total'],
                    'referencia' => $order['referencia'],
                ],
                'user' => [
                    'direccion' => $order['direccion'],
                    'depto' => $order['depto'],
                    'comuna' => $order['comuna'],
                    'ciudad' => $order['ciudad'],
                ],
                'productos' => $products
            ];
        }

        echo json_encode($result);
    } catch (Exception $e) {
        logError("Error al obtener usuario: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function products($pdo, $id, $venta_id)
{
    $query="SELECT p.*, 
                vp.cantidad as cantidad
            FROM clientes c
            INNER JOIN ventas v ON v.cliente_id = c.id
            INNER JOIN venta_productos vp ON v.id = vp.venta_id
            INNER JOIN productos p ON vp.producto_id = p.id 
            WHERE c.usuario_id = :id AND v.id = :venta_id
            ";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':venta_id', $venta_id);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $products;
}

function getAddressByClientId($pdo, $id) {
    $query = "
    SELECT d.id, 
        d.direccion, 
        comunas.nombre AS comuna, 
        ciudades.nombre AS ciudad, 
        d.depto 
    FROM direcciones d
    INNER JOIN clientes c ON c.id = d.cliente_id
    INNER JOIN comunas ON d.comuna_id = comunas.id
    INNER JOIN ciudades ON d.ciudad_id = ciudades.id
    WHERE c.usuario_id = :id AND d.estado = 1";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $address = $stmt->fetchAll(PDO::FETCH_ASSOC);
    http_response_code(200);
    echo json_encode($address);
}

function setUserPersonalData($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $query = "UPDATE clientes SET nombre = :nombre, apellido = :apellido, telefono = :telefono, rut = :rut WHERE usuario_id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->bindParam(':nombre', $data['name']);
    $stmt->bindParam(':apellido', $data['apellido']);
    $stmt->bindParam(':telefono', $data['telefono']);
    $stmt->bindParam(':rut', $data['rut']);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode([
            'status' => true,
            'message' => 'Datos actualizados correctamente'
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Error interno'
        ]);
        exit;
    }
}

function createAddressById($pdo, $id) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        $pdo->beginTransaction();

        $client = getClientId($pdo, $id);

        $moreThan4 = checkUserAddress($pdo, $client);

        if($moreThan4) {
            http_response_code(200);
            echo json_encode([
                'status' => false,
                'message' => 'Ya tienes 4 direcciones'
            ]);
            exit;
        }

        createAddressd($pdo, $client, $data);

        $pdo->commit();

        http_response_code(200);
        echo json_encode([
            'status' => true,
            'message' => 'Dirección creada con exito'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        $pdo->rollBack();
        throw $e;
    }
}

function getClientId($pdo,$id) {
    
    $query = "SELECT id FROM clientes WHERE usuario_id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $client_id = $stmt->fetchColumn();

    return $client_id;
}

function createAddressd($pdo, $client, $data) {
    $depto = intval($data['depto']) ?? null;

    $query = "INSERT INTO direcciones (cliente_id ,direccion, depto, comuna_id, ciudad_id, estado) VALUES (:cliente_id,:direccion, :depto, :comuna_id, :ciudad_id, 1)";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':cliente_id', $client);
    $stmt->bindParam(':direccion', $data['direccion']);
    $stmt->bindParam(':depto', $depto);
    $stmt->bindParam(':comuna_id', $data['comuna_id']);
    $stmt->bindParam(':ciudad_id', $data['ciudad_id']);

    $stmt->execute();
}

function checkUserAddress($pdo, $id_cliente) {
    $query = "SELECT COUNT(id) FROM direcciones WHERE cliente_id = :id AND estado = 1";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id_cliente);

    $stmt->execute();

    $userAddress = $stmt->fetchColumn();

    if($userAddress > 4) {
        return true;
    }

    return false;
}

function logError($message)
{
    $file = '../logs/errors.log';
    $current = file_get_contents($file);
    $current .= "[" . date('d-m-Y H:i:s') . "] " . $message . "\n";
    file_put_contents($file, $current);
}
