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
    getClient($pdo, $_GET['id']);
}

function getClient($pdo, $id)
{ 
    try {
        $query = "SELECT * FROM clientes WHERE usuario_id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $clientes = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($clientes) {
            echo json_encode($clientes);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Cliente no encontrado']);
        }
    } catch (Exception $e) {
        logError("Error al obtener clientes: " . $e->getMessage());
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
