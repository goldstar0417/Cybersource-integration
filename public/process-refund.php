<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Services\CybersourceService;
use Services\LoggerService;

$config = require __DIR__ . '/../config/cybersource.php';
$logger = new LoggerService(__DIR__ . '/../logs/payment.log');
$service = new CybersourceService($config, $logger);

try {
    $paymentData = [
        'reference' => uniqid('REF_'),
        'card_number' => $_POST['card_number'],
        'expiry_month' => $_POST['expiry_month'],
        'expiry_year' => $_POST['expiry_year'],
        'cvv' => $_POST['cvv'],
        'amount' => $_POST['amount']
    ];

    $result = $service->processPayment($paymentData);
    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}