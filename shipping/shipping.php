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
        $auth = validateToken();
        if ($auth && $auth->rol == 'admin') {
            updateShipping($pdo, intval($_GET['id']), intval($_GET['status']));
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'Forbidden']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
        break;
}

function handleGetRequest($pdo)
{
    $status = isset($_GET['status']) ? intval($_GET['status']) : null;

    if ($status === null || $status === 0) {
        getShipping($pdo);
    } else {
        getShippingByStatus($pdo, $status);
    }
}

function getShipping($pdo)
{
    try {
        $query = "
        SELECT 
            v.id AS venta_id,
            CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre,
            c.rut AS cliente_rut,
            c.email AS cliente_email,
            d.direccion AS direccion,
            d.depto AS depto,
            comunas.nombre AS comuna,
            ciudades.nombre AS ciudad,
            v.referencia AS referencia,
            e.estado AS estado_envio,
            e.creado_en AS fecha
        FROM 
            ventas v
        INNER JOIN clientes c ON v.cliente_id = c.id
        INNER JOIN envios e ON v.id = e.venta_id
        INNER JOIN direcciones d ON v.direccion_id = d.id
        INNER JOIN comunas ON d.comuna_id = comunas.id
        INNER JOIN ciudades ON d.ciudad_id = ciudades.id
        ORDER BY e.creado_en DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($ventas as $venta) {
            $result[] = [
                'id' => $venta['venta_id'],
                'status' => $venta['estado_envio'],
                'date' => $venta['fecha'],
                'client' => [
                    'nombre' => $venta['cliente_nombre'],
                    'rut' => $venta['cliente_rut'],
                    'email' => $venta['cliente_email']
                ],
                'reference' => $venta['referencia'],
                'address' => [
                    'direccion' => $venta['direccion'],
                    'comuna' => $venta['comuna'],
                    'ciudad' => $venta['ciudad'],
                    'depto' => $venta['depto'] ?? null
                ]
            ];
        }

        echo json_encode($result);
    } catch (Exception $e) {
        logError("Error al obtener clientes: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getShippingByStatus($pdo, $status)
{
    try {
        $query = "
        SELECT 
            v.id AS venta_id,
            CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre,
            c.rut AS cliente_rut,
            c.email AS cliente_email,
            d.direccion AS direccion,
            d.depto AS depto,
            comunas.nombre AS comuna,
            v.referencia AS referencia,
            ciudades.nombre AS ciudad,
            e.estado AS estado_envio,
            e.creado_en AS fecha
        FROM 
            ventas v
        INNER JOIN clientes c ON v.cliente_id = c.id
        INNER JOIN envios e ON v.id = e.venta_id
        INNER JOIN direcciones d ON v.direccion_id = d.id
        INNER JOIN comunas ON d.comuna_id = comunas.id
        INNER JOIN ciudades ON d.ciudad_id = ciudades.id
        WHERE 
            e.estado = :estado
        ORDER BY e.creado_en DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':estado', $status);
        $stmt->execute();
        $shippings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($shippings as $venta) {
            $result[] = [
                'id' => $venta['venta_id'],
                'status' => $venta['estado_envio'],
                'date' => $venta['fecha'],
                'client' => [
                    'nombre' => $venta['cliente_nombre'],
                    'rut' => $venta['cliente_rut'],
                    'email' => $venta['cliente_email']
                ],
                'reference' => $venta['referencia'],
                'address' => [
                    'direccion' => $venta['direccion'],
                    'comuna' => $venta['comuna'],
                    'ciudad' => $venta['ciudad'],
                    'depto' => $venta['depto'] ?? null
                ]
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) {
        logError("Error al obtener el envío: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function updateShipping($pdo, $id, $status)
{
    try {
        $query = "UPDATE envios SET estado = :estado WHERE venta_id = :venta_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':estado', $status);
        $stmt->bindParam(':venta_id', $id);
        $stmt->execute();

        http_response_code(200);
        echo json_encode(['message' => 'Shipping updated successfully']);
    } catch (Exception $e) {
        logError("Error al actualizar el envío: " . $e->getMessage());
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
