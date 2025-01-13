<?php

// $allowedOrigins = [
//     'https://flow.cl',
//     'https://www.flow.cl',
//     'https://sandbox.flow.cl',
//     'https://camarasdeseguridadfacil.cl'
// ];

// $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

// if (!empty($origin)) {
//     $parsedUrl = parse_url($origin);
//     $origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
// }

// if (empty($origin) || in_array($origin, $allowedOrigins)) {
//     header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
// } else {
//     error_log("Origin rejected: " . $origin);
//     header('HTTP/1.1 403 Forbidden');
//     die('Origen no permitido');
// }
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Manejo de preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>