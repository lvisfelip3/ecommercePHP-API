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
    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
        break;
}

function handleGetRequest($pdo)
{
    getUserOrdersById($pdo, intval($_GET['id']));
}

function handlePostRequest($pdo)
{
    setUserPersonalData($pdo);
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
            'success' => true,
            'message' => 'Datos actualizados correctamente'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error interno'
        ]);
    }
}

function logError($message)
{
    $file = '../logs/errors.log';
    $current = file_get_contents($file);
    $current .= "[" . date('d-m-Y H:i:s') . "] " . $message . "\n";
    file_put_contents($file, $current);
}
