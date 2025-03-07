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
        if ($auth && $auth->rol == 1) {
            handlePostRequest($pdo);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado']);
        }
        break;

    case 'PUT':
        $auth = validateToken();
        if ($auth && $auth->rol == 1) {
            handlePutRequest($pdo);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado']);
        }
        break;

    case 'DELETE':
        $auth = validateToken();
        if ($auth && $auth->rol == 1) {
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
        getCiudadById($pdo, intval($_GET['id']));
    } else {
        getCiudades($pdo);
    }
}

function handlePostRequest($pdo)
{
    addCiudad($pdo);
}

function handlePutRequest($pdo)
{
    updateCiudad($pdo);
}

function handleDeleteRequest($pdo)
{
    if (isset($_GET['id'])) {
        deleteCiudad($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'ID no proporcionado']);
    }
}

function getCiudades($pdo)
{
    try {
        $query = "SELECT * FROM ciudades WHERE estado = 1 OR estado = 0";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $ciudades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($ciudades);
    } catch (Exception $e) {
        logError("Error al obtener ciudades: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getCiudadById($pdo, $id)
{
    try {
        $query = "SELECT * FROM ciudades WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $ciudades = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ciudades) {
            echo json_encode($ciudades);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Ciudad no encontrada']);
        }
    } catch (Exception $e) {
        logError("Error al obtener ciudades: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addCiudad($pdo)
{
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        $nombre = isset($data['nombre']) ? $data['nombre'] : '';
        $estado = isset($data['estado']) ? $data['estado'] : '';

        if (!empty($nombre) && !empty($estado)) {
            $query = "INSERT INTO ciudades (nombre, estado) VALUES (:nombre, :estado)";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':estado', $estado);
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode(['message' => 'Ciudad creada']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al crear la ciudad']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Datos incompletos']);
        }
    } catch (Exception $e) {
        logError("Error al crear ciudad: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function updateCiudad($pdo)
{
    try {
        $putData = json_decode(file_get_contents('php://input'), true);
        $id = isset($putData['id']) ? $putData['id'] : 0;
        $nombre = isset($putData['nombre']) ? $putData['nombre'] : '';
        $estado = isset($putData['estado']) ? $putData['estado'] : '';

        if (!empty($id) && !empty($nombre) && !empty($estado)) {
            $query = "UPDATE ciudades SET nombre = :nombre, estado = :estado WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':estado', $estado);
            $stmt->bindParam(':id', $id);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Ciudad actualizada']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al actualizar la ciudad']);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'message' => 'Datos incompletos',
            ]);
        }
    } catch (Exception $e) {
        logError("Error al actualizar ciudad: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function deleteCiudad($pdo, $id)
{
    try {
        $query = "UPDATE ciudades SET estado = 2 WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'Ciudad eliminada']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Error al eliminar la ciudad']);
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
