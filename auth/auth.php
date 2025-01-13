<?php
require_once __DIR__.'/../cors.php';
require_once __DIR__.'/../vendor/autoload.php';
use \Firebase\JWT\JWT;
use Firebase\JWT\Key;

function validateToken() {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        $token = str_replace('Bearer ', '', $authHeader);

        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            return $decoded->data;
            
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['message' => 'Token inválido']);
            exit();
        }
    } else {
        http_response_code(401);
        echo json_encode(['message' => 'Falta el token de autorización']);
        exit();
    }
}
?>
