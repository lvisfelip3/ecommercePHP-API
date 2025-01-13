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

    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
        break;
}

function handleGetRequest($pdo)
{
    getOrder($pdo, $_GET['id']);
}

function getOrder($pdo, $id)
{
    try {
        $query = "
        SELECT 
            v.id AS venta_id,
            c.nombre AS cliente_nombre,
            c.apellido AS cliente_apellido,
            c.email AS cliente_email,
            c.rut AS cliente_rut,
            c.telefono AS cliente_telefono,
            d.direccion AS direccion,
            d.depto AS depto,
            p.referencia AS referencia,
            comunas.nombre AS comuna,
            ciudades.nombre AS ciudad,
            v.total AS total,
            p.metodo_pago AS metodo_pago,
            p.estado AS estado_pago,
            e.estado AS estado_envio
        FROM 
            ventas v
        INNER JOIN clientes c ON v.cliente_id = c.id
        INNER JOIN pagos p ON v.id = p.venta_id
        INNER JOIN envios e ON v.id = e.venta_id
        INNER JOIN direcciones d ON v.direccion_id = d.id
        INNER JOIN comunas ON d.comuna_id = comunas.id
        INNER JOIN ciudades ON d.ciudad_id = ciudades.id
        WHERE 
            v.referencia = :id; 
        ";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $ventas = $stmt->fetch(PDO::FETCH_ASSOC);

        $products = products($pdo, $ventas['venta_id']);

        echo json_encode([
            'payment' => [
                'method' => $ventas['metodo_pago'],
                'payment_state' => $ventas['estado_pago'],
                'shipping_state' => $ventas['estado_envio'],
                'reference' => $ventas['referencia'],
                'total' => $ventas['total']
            ],
            'productos' => $products,
            'client' => [
                'nombre' => $ventas['cliente_nombre'], 
                'apellido' => $ventas['cliente_apellido'], 
                'email' => $ventas['cliente_email'],
                'rut' => $ventas['cliente_rut'],
                'telefono' => $ventas['cliente_telefono']
            ],
            'address' => [
                'direccion' => $ventas['direccion'],
                'comuna' => $ventas['comuna'],
                'ciudad' => $ventas['ciudad'],
                'depto' => $ventas['depto']
            ]
        ]);
    } catch (Exception $e) {
        logError("Error al obtener clientes: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function products($pdo, $id)
{
    $query="SELECT p.*, vp.cantidad as cantidad
            FROM venta_productos vp
            INNER JOIN productos p ON vp.producto_id = p.id 
            WHERE vp.venta_id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $products;
}

function logError($message)
{
    $file = '../logs/errors.log';
    $current = file_get_contents($file);
    $current .= "[" . date('d-m-Y H:i:s') . "] " . $message . "\n";
    file_put_contents($file, $current);
}
