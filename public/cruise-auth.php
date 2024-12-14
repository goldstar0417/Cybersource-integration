<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Services\LoggerService;
use Services\CybersourceService;

try {
    $input = file_get_contents('php://input');
    $postData = json_decode($input, true);
    $config = require '../config/cybersource.php';
    // Header for the JWT
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
        'jti' => uniqid()
    ];
    // Body of the JWT
    $body = [
        'jti' => uniqid(),
        'iat' => time(),
        'iss' => $config['api_identifier'],
        'OrgUnitId' => $config['org_unit_id'],
        'Payload' => [
        'OrderDetails' => [
            'AccountNumber' => $postData['card_number'],
            'OrderNumber' => uniqid(),
            'Amount' => $postData['amount'],
            'CurrencyCode' => $postData['currency']
            ]
        ],
        'ReferenceId' => uniqid(),
        'ObjectifyPayload' => true,
        'exp' => time() + 3600
    ];
    
    $jwt_b64 = rtrim( base64_encode( json_encode( $body ) ), '=' );
    $header_b64 = rtrim( base64_encode( json_encode( $header ) ), '=' );
    $jwt_token = $header_b64 . '.' . $jwt_b64;
    $jwt_signature = rtrim( base64_encode( hash_hmac( 'sha256', $jwt_token, $config['api_key'], true ) ), '=' );
    $jwt_final = $jwt_token . '.' . $jwt_signature;

    echo json_encode(['success' => true, 'data' => $jwt_final]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


// $config = require '../config/cybersource.php';
//     $logger = new LoggerService('../logs/payment.log');
//     $service = new CybersourceService($config, $logger);

//     $resourcePath = '/pts/v2/payments';
//     $Payload = $service->initiateCruiseAuthentication($postData);
//     $headers = $service->getheaders('post', $resourcePath, $Payload);

//     header($header);

//     $logger->log('Cruise Authentication Request', [
//         'url' => $service->getApiUrl() . $resourcePath,
//         'headers' => $headers,
//         'payload' => $payload,
//     ]);    
//     $response = $service->client->post($resourcePath, [
//         'headers' => $headers,
//         'json' => $payload,
//     ]);

//     $result = json_decode($response->getBody()->getContents(), true);
//     $logger->log('Cruise Authentication Response', $result);
//      return $result;
