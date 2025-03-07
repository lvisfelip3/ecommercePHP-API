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
        if ($auth) {
            handlePostRequest($pdo, $auth);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado']);
        }
        break;

    case 'PUT':
        $auth = validateToken();
        if ($auth) {
            handlePutRequest($pdo, $auth);
        } else {
            http_response_code(403);
            echo json_encode(['message' => 'No autorizado']);
        }
        break;

    case 'DELETE':
        $auth = validateToken();
        if ($auth) {
            handleDeleteRequest($pdo, $auth);
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
        getRatingsByProducto($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode([
            'cod' => 5,
            'status' => false,
            'message' => 'ID de producto requerido'
        ]);
    }
}

function handlePostRequest($pdo, $auth)
{
    if (isset($_GET['action']) && $_GET['action'] === 'rate') {
        agregarRating($pdo, $auth);
    } else if (isset($_GET['action']) && $_GET['action'] === 'like') {
        likeAComment($pdo);
    }
    
}

function handlePutRequest($pdo, $auth)
{
    actualizarRating($pdo, $auth);
}

function handleDeleteRequest($pdo, $auth)
{
    if (isset($_GET['id'])) {
        eliminarRating($pdo, $auth, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode([
            'cod' => 5,
            'status' => false,
            'message' => 'ID de rating no proporcionado'
        ]);
    }
}

function getRatingsByProducto($pdo, $producto_id)
{
    try {
        $query = "
        SELECT 
            pr.id,
            pr.rating,
            pr.comentario,
            pr.fecha_rating AS fecha,
            u.nombre AS usuario,
            u.id AS usuario_id,
            COUNT(prl.id) AS likes
        FROM 
            producto_ratings pr
        LEFT JOIN 
            usuarios u ON pr.usuario_id = u.id
        LEFT JOIN 
            producto_ratings_likes prl ON pr.id = prl.comentario_id
        WHERE 
            pr.producto_id = :producto_id
        GROUP BY 
            pr.id, pr.rating, pr.comentario, pr.fecha_rating, u.nombre, u.id
        HAVING 
            pr.id IS NOT NULL;
        ";

        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':producto_id', $producto_id, PDO::PARAM_INT);
        $stmt->execute();
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($comments)) {
            http_response_code(404);
            echo json_encode([
                'status' => false,
                'message' => 'No hay ratings para este producto'
            ]);
            return;
        }

        echo json_encode([
            'status' => true,
            'producto_id' => $producto_id,
            'comments' => $comments,
        ]);

    } catch (Exception $e) {
        logError("Error al obtener ratings: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Error interno'
        ]);
    }
}

function likeAComment($pdo)
{
    try {

        $comentario_id = $_GET['comment_id'] ?? null;
        $usuario_id = $_GET['user_id'] ?? null;

        if (!$comentario_id || !$usuario_id) {    
            http_response_code(400);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Datos incompletos',
            ]);
            return;
        }

        $alreadyLiked = $pdo->prepare("SELECT * FROM producto_ratings_likes WHERE comentario_id = :comentario_id AND usuario_id = :usuario_id");
        $alreadyLiked->bindParam(':comentario_id', $comentario_id, PDO::PARAM_INT);
        $alreadyLiked->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $alreadyLiked->execute();

        if ($alreadyLiked->rowCount() > 0) {
            http_response_code(400);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Ya has dado like a este comentario',
            ]);
            exit;
        }

        $query = "INSERT INTO producto_ratings_likes (comentario_id, usuario_id) VALUES (:comentario_id, :usuario_id)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':comentario_id', $comentario_id, PDO::PARAM_INT);
        $stmt->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            echo json_encode([
                'status' => true
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => false,
                'message' => 'Error al dar like'
            ]);
        }

    } catch (Exception $e) {
        logError("Error al dar like: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => 'Error interno'
        ]);
    }
}

function agregarRating($pdo, $auth)
{
    try {
        $producto_id = $_GET['id'] ?? null;
        $rating = $_GET['rating'] ?? null;
        $comentario = $_GET['comentario'] ?? null;

        if (!$producto_id || !$rating) {
            http_response_code(400);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Datos incompletos',
            ]);
            return;
        }

        if ($rating <= 0 || $rating > 5) {
            http_response_code(400);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Rating inválido. Debe estar entre 1 y 5'
            ]);
            return;
        }

        $queryExiste = "SELECT id FROM producto_ratings 
                        WHERE producto_id = :producto_id AND usuario_id = :usuario_id";
        $stmtExiste = $pdo->prepare($queryExiste);
        $stmtExiste->bindParam(':producto_id', $producto_id, PDO::PARAM_INT);
        $stmtExiste->bindParam(':usuario_id', $auth->id, PDO::PARAM_INT);
        $stmtExiste->execute();
        $ratingExistente = $stmtExiste->fetch(PDO::FETCH_ASSOC);

        if ($ratingExistente) {
            http_response_code(400);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Ya has calificado este producto'
            ]);
            return;
        }

        $query = "INSERT INTO producto_ratings 
                  (producto_id, usuario_id, rating, comentario) 
                  VALUES (:producto_id, :usuario_id, :rating, :comentario)";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':producto_id', $producto_id, PDO::PARAM_INT);
        $stmt->bindParam(':usuario_id', $auth->id, PDO::PARAM_INT);
        $stmt->bindParam(':rating', $rating, PDO::PARAM_STR);
        $stmt->bindParam(':comentario', $comentario, PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode([
                'status' => true,
                'message' => 'Rating agregado',
                'comment' => [
                    'id' => $pdo->lastInsertId(),
                    'rating' => $rating,
                    'comentario' => $comentario,
                    'fecha' => date('d-m-Y'),
                    'usuario_id' => $auth->id,
                    'producto_id' => $producto_id,
                    'usuario' => $auth->nombre
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Error al agregar rating'
            ]);
        }
    } catch (Exception $e) {
        logError("Error al agregar rating: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'cod' => 5,
            'status' => false,
            'message' => 'Error interno'
        ]);
    }
}

function actualizarRating($pdo, $auth)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validaciones
        $id = $data['id'] ?? null;
        $rating = $data['rating'] ?? null;
        $comentario = $data['comentario'] ?? null;

        if (!$id || !$rating) {
            http_response_code(400);
            echo json_encode(['message' => 'Datos incompletos']);
            return;
        }

        // Validar rango de rating
        if ($rating < 0 || $rating > 5) {
            http_response_code(400);
            echo json_encode(['message' => 'Rating inválido. Debe estar entre 0 y 5']);
            return;
        }

        // Verificar propiedad del rating
        $queryVerificar = "SELECT producto_id FROM producto_ratings 
                           WHERE id = :id AND usuario_id = :usuario_id";
        $stmtVerificar = $pdo->prepare($queryVerificar);
        $stmtVerificar->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtVerificar->bindParam(':usuario_id', $auth->id, PDO::PARAM_INT);
        $stmtVerificar->execute();
        $ratingExistente = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

        if (!$ratingExistente) {
            http_response_code(403);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'No autorizado para modificar este rating']);
            return;
        }

        // Actualizar rating
        $query = "UPDATE producto_ratings 
                  SET rating = :rating, 
                      comentario = :comentario 
                  WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':rating', $rating, PDO::PARAM_STR);
        $stmt->bindParam(':comentario', $comentario, PDO::PARAM_STR);
        
        if ($stmt->execute()) {
            // Obtener rating promedio actualizado
            $queryPromedio = "SELECT 
                                ROUND(AVG(rating), 1) AS rating_promedio, 
                                COUNT(*) AS total_ratings 
                              FROM producto_ratings 
                              WHERE producto_id = :producto_id";
            $stmtPromedio = $pdo->prepare($queryPromedio);
            $stmtPromedio->bindParam(':producto_id', $ratingExistente['producto_id'], PDO::PARAM_INT);
            $stmtPromedio->execute();
            $promedio = $stmtPromedio->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'message' => 'Rating actualizado',
                'rating_promedio' => $promedio['rating_promedio'] ?? 0,
                'total_ratings' => $promedio['total_ratings'] ?? 0
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Error al actualizar rating']);
        }
    } catch (Exception $e) {
        logError("Error al actualizar rating: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'cod' => 5,
            'status' => false,
            'message' => 'Error interno'
        ]);
    }
}

function eliminarRating($pdo, $auth, $id)
{
    try {
        // Verificar propiedad del rating
        $queryVerificar = "SELECT producto_id FROM producto_ratings 
                           WHERE id = :id AND usuario_id = :usuario_id";
        $stmtVerificar = $pdo->prepare($queryVerificar);
        $stmtVerificar->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtVerificar->bindParam(':usuario_id', $auth->id, PDO::PARAM_INT);
        $stmtVerificar->execute();
        $ratingExistente = $stmtVerificar->fetch(PDO::FETCH_ASSOC);

        if (!$ratingExistente) {
            http_response_code(403);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'No autorizado para eliminar este rating'
            ]);
            return;
        }

        // Eliminar rating
        $query = "DELETE FROM producto_ratings WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            // Obtener rating promedio actualizado
            $queryPromedio = "SELECT 
                                ROUND(AVG(rating), 1) AS rating_promedio, 
                                COUNT(*) AS total_ratings 
                              FROM producto_ratings 
                              WHERE producto_id = :producto_id";
            $stmtPromedio = $pdo->prepare($queryPromedio);
            $stmtPromedio->bindParam(':producto_id', $ratingExistente['producto_id'], PDO::PARAM_INT);
            $stmtPromedio->execute();
            $promedio = $stmtPromedio->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'message' => 'Rating eliminado',
                'rating_promedio' => $promedio['rating_promedio'] ?? 0,
                'total_ratings' => $promedio['total_ratings'] ?? 0
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'cod' => 5,
                'status' => false,
                'message' => 'Error al eliminar rating'
            ]);
        }
    } catch (Exception $e) {
        logError("Error al eliminar rating: " . $e->getMessage());
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
?>