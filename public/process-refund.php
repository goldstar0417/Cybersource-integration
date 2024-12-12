<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\CybersourceService;
use App\Services\Logger;
use App\Exceptions\PaymentException;

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Initialize services
try {
    $config = require __DIR__ . '/../config/cybersource.php';
    $logger = new Logger('payment.log');
    $service = new CybersourceService($config, $logger);

    // Validate and sanitize input
    $requiredFields = ['transaction_id', 'amount', 'currency'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            throw new PaymentException("Missing required field: $field");
        }
    }

    $refundData = [
        'amount' => filter_var($_POST['amount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
        'currency' => filter_var($_POST['currency'], FILTER_SANITIZE_STRING)
    ];

    $transactionId = filter_var($_POST['transaction_id'], FILTER_SANITIZE_STRING);

    // Process refund
    $result = $service->processRefund($transactionId, $refundData);
    
    // Send response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'refund_id' => $result['id'] ?? null,
        'status' => $result['status'] ?? null,
        'transaction_id' => $transactionId
    ]);

} catch (PaymentException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'error_data' => $e->getErrorData()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}