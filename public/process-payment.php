<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\CybersourceService;
use App\Services\Logger;
use App\Exceptions\PaymentException;

// Get JSON input
$input = file_get_contents('php://input');
$_POST = json_decode($input, true) ?? [];

// Initialize services
try {
    $logger = new Logger(__DIR__ . '/../logs/payment.log');
    $config = require __DIR__ . '/../config/cybersource.php';
    $service = new CybersourceService($config, $logger);

    // Validate and sanitize input
    $requiredFields = ['amount', 'currency', 'card_number', 'expiry_month', 'expiry_year', 'cvv'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            throw new PaymentException("Missing required field: $field");
        }
    }

    $paymentData = [
        'amount' => filter_var($_POST['amount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
        'currency' => filter_var($_POST['currency'], FILTER_SANITIZE_STRING),
        'order_id' => 'ORDER-' . uniqid(),
        'card' => [
            'number' => preg_replace('/\s+/', '', $_POST['card_number']),
            'expiry_month' => str_pad($_POST['expiry_month'], 2, '0', STR_PAD_LEFT),
            'expiry_year' => $_POST['expiry_year'],
            'cvv' => $_POST['cvv']
        ]
    ];

    $result = $service->processPayment($paymentData);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'transaction_id' => $result['id'] ?? null]);

} catch (PaymentException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}