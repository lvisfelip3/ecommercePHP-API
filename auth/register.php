<?php
require '../config.php';
require '../cors.php';

try {
    $data = json_decode(file_get_contents("php://input"));

    $email = $data->email;
    $password = password_hash($data->password, PASSWORD_DEFAULT);
    $nombre = $data->nombre;

    $query = "INSERT INTO usuarios (email, password, nombre) VALUES (:email, :password, :nombre)";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password', $password);
    $stmt->bindParam(':nombre', $nombre);

    if ($stmt->execute()) {
        echo json_encode(['message' => 'Usuario registrado con éxito.']);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Error al registrar usuario.']);
    }
} catch (Exception $e) {
    logError("Error al registrar usuario: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array(
        "message" => "Error interno."
    ));
}

function logError($message)
{
    $file = '../logs/errors.log';
    $current = file_get_contents($file);
    $current .= "[" . date('d-m-Y H:i:s') . "] ".  $message . "\n";
    file_put_contents($file, $current);
}
