<?php
require '../config.php';
require '../cors.php';

require_once __DIR__.'/../vendor/autoload.php';

use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__. '/../');
$dotenv->load();

define('RECAPTCHA_KEY', $_ENV['RECAPTCHA_KEY']);

$data = json_decode(file_get_contents("php://input"), true);

$recaptchaToken = isset($data) ? $data['token'] : null;

  if($recaptchaToken == null) {
    echo json_encode([
        'message' => 'reCaptcha token is missing',
        'status'=> false
    ]);
    exit;
  }

  $userIp = $_SERVER['REMOTE_ADDR'];
  $response=file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".RECAPTCHA_KEY."&response=".$recaptchaToken."&remoteip=".$userIp);
  $responseData = json_decode($response);
  
  if($responseData -> success == false){
    echo json_encode([
        'message' => 'reCaptcha token is invalid',
        'status'=> false
    ]);
    exit;
  } else {
    echo json_encode([
        'message' => 'reCaptcha token is valid',
        'status'=> true
    ]);
  }

?>