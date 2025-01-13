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
            handlePostRequest($pdo);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado']);
        }
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
        getComunaById($pdo, intval($_GET['id']));
    } elseif (isset($_GET['ciudad_id']) && !empty($_GET['ciudad_id'])) {
        getComunasByCiudad($pdo, intval($_GET['ciudad_id']));
    } else {
        getComunas($pdo);
    }
}

function handlePostRequest($pdo)
{
    addComuna($pdo);
}

function handlePutRequest($pdo)
{
    updateComuna($pdo);
}

function handleDeleteRequest($pdo)
{
    if (isset($_GET['id'])) {
        deleteComuna($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'ID no proporcionado']);
    }
}

function getComunas($pdo)
{
    try {
        $query = "SELECT * FROM comunas WHERE estado = 1 OR estado = 0";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $comunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comunas);
    } catch (Exception $e) {
        logError("Error al obtener comunas: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getComunasByCiudad($pdo, $id)
{
    try {
        $query = "SELECT * FROM comunas WHERE ciudad_id = :id AND estado = 1 OR estado = 0";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $comunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comunas);
    } catch (Exception $e) {
        logError("Error al obtener comunas: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getComunaById($pdo, $id)
{
    try {
        $query = "SELECT * FROM comunas WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $comunas = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($comunas) {
            echo json_encode($comunas);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Comuna no encontrada']);
        }
    } catch (Exception $e) {
        logError("Error al obtener comunas: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addComuna($pdo)
{
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        $nombre = isset($data['nombre']) ? $data['nombre'] : '';
        $ciudad_id = isset($data['ciudad_id']) ? $data['ciudad_id'] : '';
        $estado = isset($data['estado']) ? $data['estado'] : '';

        if (!empty($nombre) && !empty($estado) && !empty($ciudad_id)) {
            $query = "INSERT INTO comunas (nombre, ciudad_id, estado) VALUES (:nombre, :ciudad_id ,:estado)";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':ciudad_id', $ciudad_id);
            $stmt->bindParam(':estado', $estado);
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(['message' => 'Comuna creada']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al crear la comuna']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Datos incompletos']);
        }
    } catch (Exception $e) {
        logError("Error al crear comuna: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function updateComuna($pdo)
{
    try {
        $putData = json_decode(file_get_contents('php://input'), true);
        $id = isset($putData['id']) ? $putData['id'] : 0;
        $nombre = isset($putData['nombre']) ? $putData['nombre'] : '';
        $ciudad_id = isset($putData['ciudad_id']) ? $putData['ciudad_id'] : ''; 
        $estado = isset($putData['estado']) ? $putData['estado'] : '';

        if (!empty($id) && !empty($nombre) && !empty($estado) && !empty($ciudad_id)) {
            $query = "UPDATE comunas SET nombre = :nombre, ciudad_id = :ciudad_id ,estado = :estado WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':ciudad_id', $ciudad_id);
            $stmt->bindParam(':estado', $estado);
            $stmt->bindParam(':id', $id);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Comuna actualizada']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al actualizar la comuna']);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'message' => 'Datos incompletos',
            ]);
        }
    } catch (Exception $e) {
        logError("Error al actualizar comuna: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function deleteComuna($pdo, $id)
{
    try {
        $query = "UPDATE comunas SET estado = 2 WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'Comuna eliminada']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Error al eliminar la comuna']);
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
