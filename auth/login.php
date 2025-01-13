<?php
require '../config.php';
require '../cors.php';

require_once __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

use \Firebase\JWT\JWT;

define('SITE_API_URL', $_ENV['SITE_API_URL']);
define('BASE_URL', $_ENV['BASE_URL']);

try {
    $data = json_decode(file_get_contents("php://input"));

    $email = filter_var($data->email, FILTER_VALIDATE_EMAIL);

    if(!$email) {
        http_response_code(400);
        echo json_encode(array(
            "status" => "error",
            "message" => "Email inválido."
        ));
        exit;
    }
    
    $password = $data->password;

    $query = "SELECT * FROM usuarios WHERE email = :email LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $issuer_claim = SITE_API_URL;
        $audience_claim = BASE_URL;
        $issuedat_claim = time();
        $notbefore_claim = $issuedat_claim;
        $expire_claim = $issuedat_claim + 3600;

        $token = array(
            "iss" => $issuer_claim,
            "aud" => $audience_claim,
            "iat" => $issuedat_claim,
            "nbf" => $notbefore_claim,
            "exp" => $expire_claim,
            "data" => array(
                "id" => $user['id'],
                "nombre" => $user['nombre'],
                "email" => $user['email'],
                "rol" => $user['rol']
            )
        );

        http_response_code(200);

        $jwt = JWT::encode($token, $_ENV['JWT_SECRET'], 'HS256');
        echo json_encode(
            array(
                "message" => "Inicio de sesión exitoso.",
                "token" => $jwt,
                "expire_at" => $expire_claim
            )
        );
    } else {
        http_response_code(401);
        echo json_encode(array(
            "message" => "Inicio de sesión fallido."
        ));
    }
} catch (Exception $e) {
    logError("Error al iniciar sesión: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array(
        "message" => "Error interno."
    ));
}

function logError($message)
{
    $file = '../logs/errors.log';
    $current = file_get_contents($file);
    $current .= "[" . date('d-m-Y H:i:s') . "] " . $message . "\n";
    file_put_contents($file, $current);
}

?>