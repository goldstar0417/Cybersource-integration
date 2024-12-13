<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Services\LoggerService;
use Services\CybersourceService;

header('Content-Type: application/json');

try {
    $input = file_get_contents('php://input');
    $postData = json_decode($input, true);
    
    $config = require '../config/cybersource.php';
    $logger = new LoggerService('../logs/payment.log');
    $service = new CybersourceService($config, $logger);

    $cruiseResponse = $service->initiateCruiseAuthentication($postData);
    echo json_encode(['success' => true, 'data' => $cruiseResponse]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}