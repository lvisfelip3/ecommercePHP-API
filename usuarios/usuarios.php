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
            echo json_encode(['message' => 'No autorizado']);
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
        getUserById($pdo, intval($_GET['id']));
    } else {
        getUsers($pdo);
    }
}

function handlePostRequest($pdo)
{
    addUser($pdo);
}

function handlePutRequest($pdo)
{
    updateUser($pdo);
}

function handleDeleteRequest($pdo)
{
    if (isset($_GET['id'])) {
        deleteUser($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'ID no proporcionado']);
    }
}

function getUsers($pdo)
{
    try {
        $query = "SELECT id, nombre, email, rol, creado_en FROM usuarios";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($usuarios);
    } catch (Exception $e) {
        logError("Error al obtener usuarios: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getUserById($pdo, $id)
{
    try {
        $query = "SELECT id, nombre, email, rol, creado_en FROM usuarios WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario) {
            echo json_encode($usuario);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Usuario no encontrado']);
        }
    } catch (Exception $e) {
        logError("Error al obtener usuario: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addUser($pdo)
{

    try {
        $data = json_decode(file_get_contents("php://input"), true);

        $nombre = filter_var($data['nombre'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
        $password = $data['password'];
        $rol = filter_var($data['rol'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($nombre) && !empty($email) && !empty($password)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO usuarios (nombre, email, password, rol) VALUES (:nombre, :email, :password, :rol)";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':rol', $rol);

            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode([
                    'status' => true,
                    'message' => 'Usuario creado con éxito',
                    'user' => [
                        'id' => $pdo->lastInsertId(),
                        'nombre' => $nombre,
                        'email' => $email,
                        'rol' => $rol
                    ]
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => false,
                    'message' => 'Error al crear el usuario',
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'status' => false,
                'message' => 'Datos incompletos'
            ]);
        }
    } catch (Exception $e) {
        logError("Error al crear usuario: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Error interno'
        ]);
    }
}

function updateUser($pdo)
{
    try {
        $putData = json_decode(file_get_contents('php://input'), true);

        $id = filter_var($putData['id'], FILTER_SANITIZE_NUMBER_INT);
        $nombre = filter_var($putData['nombre'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email = filter_var($putData['email'], FILTER_SANITIZE_EMAIL);
        $rol = filter_var($putData['rol'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $password = filter_var($putData['password'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!empty($id) && !empty($nombre) && !empty($email) && !empty($rol)) {

            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $query = "UPDATE usuarios SET nombre = :nombre, email = :email, rol = :rol, password = :password WHERE id = :id";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':password', $hashedPassword);
                $stmt->bindParam(':nombre', $nombre);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':rol', $rol);
                $stmt->bindParam(':id', $id);
            } else {
                $query = "UPDATE usuarios SET nombre = :nombre, email = :email, rol = :rol WHERE id = :id";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':nombre', $nombre);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':rol', $rol);
                $stmt->bindParam(':id', $id);
            }


            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    echo json_encode([
                        'status' => true,
                        'message' => 'Usuario actualizado',
                        'user' => [
                            'id' => $id,
                            'nombre' => $nombre,
                            'email' => $email,
                            'rol' => $rol
                        ]
                    ]);
                } else {
                    echo json_encode([
                        'status' => false,
                        'message' => 'No se realizaron cambios en el usuario'
                    ]);
                }
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => false,
                    'message' => 'Error al actualizar el usuario'
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'status' => false,
                'message' => 'Datos incompletos'
            ]);
        }
    } catch (Exception $e) {
        logError("Error al actualizar usuario: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Error interno'
        ]);
    }
}

function deleteUser($pdo, $id)
{
    try {
        $query = "DELETE FROM usuarios WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        if ($stmt->execute()) {
            echo json_encode(['message' => 'Usuario eliminado']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Error al eliminar el usuario']);
        }
    } catch (Exception $e) {
        logError("Error al eliminar usuario: " . $e->getMessage());
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
