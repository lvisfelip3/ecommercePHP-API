<?php 
require '../config.php';
require '../cors.php';
require_once 'PaymentService.php';

$paymentService = new PaymentService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {

        if (!isset($_POST['token'])) {
            throw new Exception("No se recibió el token de Flow");
        }

        $token = $_POST['token'];

        $status = $paymentService->getTransactionStatus($token);
        $result = $paymentService->processConfirmation($status);

        echo json_encode($result);
        
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Flow Confirmation Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
?>