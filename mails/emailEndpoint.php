<?php
require_once __DIR__.'/../cors.php';
require __DIR__.'/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $destinatario = $data['email'] ?? '';
    $asunto = $data['subject'] ?? '';
    $mensaje = $data['message'] ?? '';

    if (!$destinatario || !$asunto || !$mensaje) {
        echo json_encode(['success' => false, 'message' => 'Faltan datos']);
        exit;
    }

    echo enviarCorreo($destinatario, $asunto, $mensaje);
}


function enviarCorreo($destinatario, $asunto, $mensajeHtml) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'mail.camarasdeseguridadfacil.cl';
        $mail->SMTPAuth = true;
        $mail->Username = 'ventas@camarasdeseguridadfacil.cl';
        $mail->Password = $_ENV['MAIL_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->setFrom('ventas@camarasdeseguridadfacil.cl', 'Camara de Seguridad Facil');
        $mail->addAddress($destinatario);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $mensajeHtml;

        $mail->send();
        return json_encode([
            'success' => true,
            'message' => 'Correo enviado correctamente'
        ]);
    } catch (Exception $e) {
        return json_encode([
            'success' => false,
            'message' => 'Error al enviar el correo: ' . $mail->ErrorInfo
        ]);
    }
}