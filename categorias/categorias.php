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
        getCategoryById($pdo, intval($_GET['id']));
    } else {
        getCategories($pdo);
    }
}

function handlePostRequest($pdo)
{
    addCategory($pdo);
}

function handlePutRequest($pdo)
{
    updateCategory($pdo);
}

function handleDeleteRequest($pdo)
{
    if (isset($_GET['id'])) {
        deleteCategory($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'ID no proporcionado']);
    }
}

function getCategories($pdo)
{
    try {
        $query = "SELECT * FROM categorias";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($categorias);
    } catch (Exception $e) {
        logError("Error al obtener categorias: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getCategoryById($pdo, $id)
{
    try {
        $query = "SELECT * FROM categorias WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $categoria = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($categoria) {
            echo json_encode($categoria);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Categoría no encontrada']);
        }
    } catch (Exception $e) {
        logError("Error al obtener categoría: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addCategory($pdo)
{
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        $nombre = isset($data['nombre']) ? $data['nombre'] : '';
        $descripcion = isset($data['descripcion']) ? $data['descripcion'] : '';

        if (!empty($nombre) && !empty($descripcion)) {
            $query = "INSERT INTO categorias (nombre, descripcion) VALUES (:nombre, :descripcion)";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode([
                    'status' => true,
                    'message' => 'Categoría creada',
                    'data' => [
                        'id' => $pdo->lastInsertId(),
                        'nombre' => $nombre,
                        'descripcion' => $descripcion
                    ]
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al crear la categoría']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Datos incompletos']);
        }
    } catch (Exception $e) {
        logError("Error al crear categoría: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function updateCategory($pdo)
{
    try {
        $putData = json_decode(file_get_contents('php://input'), true);
        $id = isset($putData['id']) ? $putData['id'] : 0;
        $nombre = isset($putData['nombre']) ? $putData['nombre'] : '';
        $descripcion = isset($putData['descripcion']) ? $putData['descripcion'] : '';

        if (!empty($id) && !empty($nombre) && !empty($descripcion)) {
            $query = "UPDATE categorias SET nombre = :nombre, descripcion = :descripcion WHERE id = :id";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            $stmt->bindParam(':id', $id);
            if ($stmt->execute()) {
                echo json_encode(['message' => 'Categoría actualizada']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al actualizar la categoría']);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'message' => 'Datos incompletos',
                'datos' => $putData,
                'id' => $id,
                'nombre' => $nombre,
                'descripcion' => $descripcion
            ]);
        }
    } catch (Exception $e) {
        logError("Error al actualizar categoría: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function deleteCategory($pdo, $id)
{
    try {
        $query = "DELETE FROM categorias WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'Categoría eliminada']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Error al eliminar la categoría']);
        }
    } catch (Exception $e) {
        logError("Error al eliminar categoría: " . $e->getMessage());
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
