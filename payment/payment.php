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
            updatePayment($pdo, intval($_GET['id']), intval($_GET['status']));
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
        getPayments($pdo);
    } else {
        getPaymentsByStatus($pdo, $status);
    }
}

function getPayments($pdo)
{
    try {
        $query = "
        SELECT 
            p.id AS pago_id,
            v.id AS venta_id,
            CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre,
            c.rut AS cliente_rut,
            c.email AS cliente_email,
            p.monto as monto,
            p.metodo_pago as metodo_pago,
            p.tipo_pago,
            v.referencia AS venta_referencia,
            p.referencia AS referencia,
            p.estado AS estado_pago,
            p.creado_en AS fecha
        FROM 
            pagos p
        INNER JOIN ventas v ON p.venta_id = v.id
        INNER JOIN clientes c ON v.cliente_id = c.id
        ORDER BY p.creado_en DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($payments as $pay) {
            $result[] = [
                'id' => $pay['pago_id'],
                'status' => $pay['estado_pago'],
                'date' => $pay['fecha'],
                'client' => [
                    'nombre' => $pay['cliente_nombre'],
                    'rut' => $pay['cliente_rut'],
                    'email' => $pay['cliente_email']
                ],
                'venta_referencia' => $pay['venta_referencia'],
                'amount' => $pay['monto'],
                'method' => $pay['metodo_pago'],
                'submethod' => $pay['tipo_pago'],
                'reference' => $pay['referencia'],
                'sale' => $pay['venta_id']
            ];
        }

        echo json_encode($result);
    } catch (Exception $e) {
        logError("Error al obtener clientes: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getPaymentsByStatus($pdo, $status)
{
    try {
        $query = "
        SELECT 
            p.id AS pago_id,
            v.id AS venta_id,
            CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre,
            c.rut AS cliente_rut,
            c.email AS cliente_email,
            p.monto as monto,
            v.referencia AS venta_referencia,
            p.metodo_pago as metodo_pago,
            p.tipo_pago,
            p.referencia AS referencia,
            p.estado AS estado_pago,
            p.creado_en AS fecha
        FROM 
            pagos p
        INNER JOIN ventas v ON p.venta_id = v.id
        INNER JOIN clientes c ON v.cliente_id = c.id
        WHERE 
            p.estado = :estado
        ORDER BY p.creado_en DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':estado', $status);
        $stmt->execute();
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($payments as $pay) {
            $result[] = [
                'id' => $pay['pago_id'],
                'status' => $pay['estado_pago'],
                'date' => $pay['fecha'],
                'client' => [
                    'nombre' => $pay['cliente_nombre'],
                    'rut' => $pay['cliente_rut'],
                    'email' => $pay['cliente_email']
                ],
                'venta_referencia' => $pay['venta_referencia'],
                'amount' => $pay['monto'],
                'method' => $pay['metodo_pago'],
                'submethod' => $pay['tipo_pago'],
                'reference' => $pay['referencia'],
                'sale' => $pay['venta_id']
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) {
        logError("Error al obtener el envío: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function updatePayment($pdo, $id, $status)
{
    try {
        $query = "UPDATE pagos SET estado = :estado WHERE venta_id = :venta_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':estado', $status);
        $stmt->bindParam(':venta_id', $id);
        $stmt->execute();

        http_response_code(200);
        echo json_encode(['message' => 'Payment updated successfully']);
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
