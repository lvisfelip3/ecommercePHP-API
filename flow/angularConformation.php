<?php 
require '../config.php';
require '../cors.php';
require_once 'PaymentService.php';

$paymentService = new PaymentService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $token = getTokenFromSaleRef($pdo, $data['saleRef']);

        if (!isset($token)) {
            throw new Exception("No se recibió el token de Flow");
        }

        $status = $paymentService->getTransactionStatus($token);
        $result = $paymentService->handleError($status);

        header('Content-Type: application/json');
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

function getTokenFromSaleRef($pdo, $saleRef) {
    $query = "SELECT token FROM ordenes_pendientes_flow WHERE sale_ref = :saleRef";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':saleRef', $saleRef);
    $stmt->execute();
    return $stmt->fetchColumn();
}
?>