<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Services\LoggerService;
use Services\CybersourceService;
use Exceptions\PaymentException;

// Set headers for JSON response
header('Content-Type: application/json');

try {
    // Get JSON input and decode it
    $input = file_get_contents('php://input');
    $postData = json_decode($input, true);
    
    // Log the received data
    $config = require '../config/cybersource.php';
    $logger = new LoggerService('../logs/payment.log');
    $logger->log('Received data', $postData);

    // Validate the input - Add new required fields
    $requiredFields = [
        'card_number', 'expiry_month', 'expiry_year', 'cvv', 'amount',
        'firstName', 'lastName', 'email', 'address1', 'locality', 'country',
        'administrativeArea', 'postalCode'  // Add these two new required fields
    ];

    foreach ($requiredFields as $field) {
        if (empty($postData[$field])) {
            throw new PaymentException("Missing required field: $field");
        }
    }

    $service = new CybersourceService($config, $logger);

    // Process payment with all required fields - Add new fields
    $paymentData = [
        'card_number' => $postData['card_number'],
        'expiry_month' => $postData['expiry_month'],
        'expiry_year' => $postData['expiry_year'],
        'cvv' => $postData['cvv'],
        'amount' => $postData['amount'],
        'currency' => 'USD',
        'firstName' => $postData['firstName'],
        'lastName' => $postData['lastName'],
        'email' => $postData['email'],
        'address1' => $postData['address1'],
        'locality' => $postData['locality'],
        'country' => $postData['country'],
        'administrativeArea' => $postData['administrativeArea'],  // Add State
        'postalCode' => $postData['postalCode']  // Add ZIP code
    ];

    $result = $service->processPayment($paymentData);
    echo json_encode(['success' => true, 'data' => $result]);

} catch (PaymentException $e) {
    $logger->log('Payment Error', ['error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    $logger->log('System Error', ['error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred']);
}