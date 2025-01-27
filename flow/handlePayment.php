<?php 
require_once 'PaymentService.php';
require_once '../vendor/autoload.php';
require '../config.php';
require '../cors.php';

$paymentService = new PaymentService($pdo);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $orderData = json_decode(file_get_contents("php://input"), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON data");
        }

        $verifiedProducts = $paymentService->verifyProducts($orderData['products']);
        $result = $paymentService->initiatePayment($orderData, $verifiedProducts);
        
        http_response_code(201);
        echo json_encode($result);
    } else {
        http_response_code(405);
        echo json_encode(['message' => 'Method Not Allowed']);
    }
} catch (Exception $e) {
    error_log("Payment Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['message' => $e->getMessage()]);
}
?>