<?php
require 'config.php';
require 'vendor/autoload.php';
require './cors.php';
require_once './auth/auth.php';

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

    case 'DELETE':
        $auth = validateToken();
        if ($auth && $auth->rol == 'admin') {
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
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        getProductById($pdo, intval($_GET['id']));
        exit;
    } elseif (isset($_GET['name']) && !empty($_GET['name'])) {
        getProductsByName($pdo, $_GET['name']);
        exit;
    } elseif (isset($_GET['action']) && $_GET['action'] === 'maintainer') {
        getProductsAdmin($pdo);
        exit;
    }

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;

    if ($limit <= 0) $limit = 10;
    if ($page <= 0) $page = 1;

    $offset = ($page - 1) * $limit;
    $totalProducts = getTotalProducts($pdo);

    if (isset($_GET['search']) && !empty($_GET['search'])) {
        getProductsBySearch($pdo, $_GET['search'], $limit, $offset, $page, $totalProducts);

    } elseif (isset($_GET['category_id']) && !empty($_GET['category_id'])) {
        getProductsByCategory($pdo, intval($_GET['category_id']), $limit, $offset, $page, $totalProducts);

    } elseif (isset($_GET['maxPrice']) && !empty($_GET['maxPrice'])) {
        getProductsByMaxPrice($pdo, intval($_GET['maxPrice']), $limit, $offset, $page, $totalProducts);

    } else {
        getProducts($pdo, $limit, $offset, $page, $totalProducts);
    }
}

function handlePostRequest($pdo)
{
    if (isset($_POST['id'])) {
        updateProduct($pdo);
    } else {
        addProduct($pdo);
    }
}

function handlePutRequest($pdo)
{
    updateProduct($pdo);
}

function handleDeleteRequest($pdo)
{
    if (isset($_GET['id'])) {
        deleteProduct($pdo, intval($_GET['id']));
    } else {
        http_response_code(400);
        echo json_encode(['message' => 'ID no proporcionado']);
    }
}

function getProducts($pdo, $limit, $offset, $page, $totalProducts)
{
    try {
        $query = "SELECT * FROM productos WHERE estado = 1 ORDER BY stock DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'products' => $products,
            'pagination' => [
                'total' => $totalProducts,
                'limit' => $limit,
                'page' => $page,
                'pages' => ceil($totalProducts / $limit)
            ]
        ]);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getProductsAdmin($pdo)
{
    try {
        $query = "SELECT * FROM productos WHERE estado = 1 ORDER BY stock DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($products);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getProductsByName($pdo, $name)
{
    try {
        $query = "SELECT * FROM productos WHERE slug = :name AND estado = 1";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($product);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getTotalProducts($pdo) {
    try {
        $query = "SELECT COUNT(id) FROM productos WHERE estado = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $total = $stmt->fetchColumn();
        return intval($total);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getProductsByCategory($pdo, $categoryId, $limit, $offset, $page, $totalProducts)
{
    try {
        $query = "SELECT * 
                    FROM productos 
                    WHERE categoria_id = :categoryId AND estado = 1 
                    ORDER BY stock DESC 
                    LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':categoryId', $categoryId);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'products' => $products,
            'pagination' => [
                'total' => $totalProducts,
                'limit' => $limit,
                'page' => $page,
                'pages' => ceil($totalProducts / $limit)
            ]
        ]);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getProductsByMaxPrice($pdo, $maxPrice, $limit, $offset, $page, $totalProducts)
{
    try {
        $query = "SELECT * 
                    FROM productos 
                    WHERE precio <= :maxPrice AND estado = 1
                    ORDER BY stock DESC
                    LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':maxPrice', $maxPrice);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'products' => $products,
            'pagination' => [
                'total' => $totalProducts,
                'limit' => $limit,
                'page' => $page,
                'pages' => ceil($totalProducts / $limit)
            ]
        ]);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getProductsBySearch($pdo, $search, $limit, $offset, $page, $totalProducts)
{
    try {
        $searchTerm = "%" . $search . "%";
        $query = "SELECT * 
                    FROM productos 
                    WHERE (nombre LIKE :search OR descripcion LIKE :search) AND (estado = 1)
                    ORDER BY stock DESC
                    LIMIT :limit OFFSET :offset
                    ";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':search', $searchTerm);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'products' => $products,
            'pagination' => [
                'total' => $totalProducts,
                'limit' => $limit,
                'page' => $page,
                'pages' => ceil($totalProducts / $limit)
            ]
        ]);
    } catch (Exception $e) {
        logError("Error al obtener productos: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function getProductById($pdo, $id)
{
    try {
        $query = "SELECT * FROM productos WHERE id = :id AND estado = 1";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            echo json_encode($product);
        } else {
            http_response_code(404);
            echo json_encode(['message' => 'Producto no encontrado']);
        }
    } catch (Exception $e) {
        logError("Error al obtener producto: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function addProduct($pdo)
{
    try {
        $nombre = isset($_POST['nombre']) ? $_POST['nombre'] : '';
        $descripcion = isset($_POST['descripcion']) ? $_POST['descripcion'] : '';
        $precio = isset($_POST['precio']) ? $_POST['precio'] : 0;
        $stock = isset($_POST['stock']) ? $_POST['stock'] : 0;
        $categoria = isset($_POST['categoria_id']) ? $_POST['categoria_id'] : '';
        $slug = generateSlug($nombre);

        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {
            $imagen = $_FILES['imagen'];
            $target_dir = "uploads/";
            $target_file = $target_dir . basename($imagen["name"]);

            if (move_uploaded_file($imagen["tmp_name"], $target_file)) {
                $imagenURL = "https://camarasdeseguridadfacil.cl/api/" . $target_file;

                $query = "INSERT INTO productos (nombre, descripcion, precio, stock, categoria_id, imagen, slug) VALUES (:nombre, :descripcion, :precio, :stock, :categoria, :imagen, :slug)";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':nombre', $nombre);
                $stmt->bindParam(':descripcion', $descripcion);
                $stmt->bindParam(':precio', $precio);
                $stmt->bindParam(':stock', $stock);
                $stmt->bindParam(':categoria', $categoria);
                $stmt->bindParam(':imagen', $imagenURL);
                $stmt->bindParam(':slug', $slug);
                $stmt->execute();

                http_response_code(201);
                echo json_encode(['message' => 'Producto creado1']);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al subir la imagen']);
            }
        } else {

            $query = "INSERT INTO productos (nombre, descripcion, precio, stock, categoria_id) VALUES (:nombre, :descripcion, :precio, :stock, :categoria)";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            $stmt->bindParam(':precio', $precio);
            $stmt->bindParam(':stock', $stock);
            $stmt->bindParam(':categoria', $categoria);
            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode([
                    'message' => 'Producto creado2',
                    'post' => $_POST
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al crear el producto']);
            }
        }
    } catch (Exception $e) {
        logError("Error al crear el producto: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}


function updateProduct($pdo)
{
    try {
        $id = isset($_POST['id']) ? intval($_POST['id']) : null;
        $nombre = isset($_POST['nombre']) ? $_POST['nombre'] : '';
        $descripcion = isset($_POST['descripcion']) ? $_POST['descripcion'] : '';
        $precio = isset($_POST['precio']) ? $_POST['precio'] : 0;
        $stock = isset($_POST['stock']) ? $_POST['stock'] : 0;
        $categoria = isset($_POST['categoria_id']) ? $_POST['categoria_id'] : '';

        // Inicializar la consulta de actualización sin imagen
        $query = "UPDATE productos SET nombre = :nombre, descripcion = :descripcion, precio = :precio, stock = :stock, categoria_id = :categoria WHERE id = :id";
        $params = [
            ':nombre' => $nombre,
            ':descripcion' => $descripcion,
            ':precio' => $precio,
            ':stock' => $stock,
            ':categoria' => $categoria,
            ':id' => $id
        ];

        // Si hay una nueva imagen, actualizar la ruta en la base de datos
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] == 0) {
            $imagen = $_FILES['imagen'];
            $target_dir = "uploads/";
            $target_file = $target_dir . basename($imagen["name"]);

            if (move_uploaded_file($imagen["tmp_name"], $target_file)) {
                $imagenURL = "https://camarasdeseguridadfacil.cl/api/" . $target_file;
                // Actualizar la consulta para incluir la imagen
                $query = "UPDATE productos SET nombre = :nombre, descripcion = :descripcion, precio = :precio, stock = :stock, categoria_id = :categoria, imagen = :imagen WHERE id = :id";
                $params[':imagen'] = $imagenURL;
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Error al subir la imagen']);
                return;
            }
        }

        // Ejecutar la consulta
        $stmt = $pdo->prepare($query);
        if ($stmt->execute($params)) {
            http_response_code(200);
            echo json_encode(['message' => 'Producto actualizado']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Error al actualizar el producto']);
        }
    } catch (Exception $e) {
        logError("Error al actualizar producto: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function generateSlug($string) {
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
}

function deleteProduct($pdo, $id)
{
    try {
        $query = "UPDATE productos SET estado = 2 WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        echo json_encode(['message' => 'Producto eliminado']);
    } catch (Exception $e) {
        logError("Error al eliminar producto: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Error interno']);
    }
}

function logError($message)
{
    $file = './logs/errors.log';
    $current = file_get_contents($file);
    $backtrace = debug_backtrace();
    $fileLine = $backtrace[0]['file'] . ':' . $backtrace[0]['line'];
    $current .= "[" . date('d-m-Y H:i:s') . "] " . $message . " ({$fileLine})\n";
    file_put_contents($file, $current);
}
