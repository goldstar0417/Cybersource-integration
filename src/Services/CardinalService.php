<?php
namespace App\Services;

use App\Exceptions\PaymentException;

class CardinalService 
{
    private $config;
    private $logger;
    private $apiEndpoint = 'https://centinelapi.cardinalcommerce.com/V2/Cruise/Authenticate';

    public function __construct(array $config, Logger $logger) 
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function initiate(array $paymentData): array 
    {
        try {
            $orderDetails = [
                'Amount' => $paymentData['amount'],
                'CurrencyCode' => $paymentData['currency'],
                'OrderNumber' => $paymentData['order_id'],
                'TransactionType' => 'C'
            ];

            $payload = [
                'OrderDetails' => $orderDetails,
                'Consumer' => [
                    'Account' => [
                        'AccountNumber' => $paymentData['card']['number'],
                        'ExpirationMonth' => $paymentData['card']['expiry_month'],
                        'ExpirationYear' => $paymentData['card']['expiry_year'],
                        'CardCode' => $paymentData['card']['cvv']
                    ]
                ]
            ];

            return $this->sendRequest($payload);

        } catch (\Exception $e) {
            $this->logger->error('3DS authentication failed', [
                'error' => $e->getMessage(),
                'order_id' => $paymentData['order_id'] ?? 'N/A'
            ]);
            throw new PaymentException('3DS authentication failed: ' . $e->getMessage());
        }
    }

    private function generateJWT(array $paymentData): string 
    {
        try {
            $header = [
                'typ' => 'JWT',
                'alg' => 'HS256'
            ];

            $payload = [
                'jti' => uniqid(),
                'iat' => time(),
                'exp' => time() + 3600,
                'iss' => $this->config['cardinal']['api_identifier'],
                'OrgUnitId' => $this->config['cardinal']['org_unit_id'],
                'ReferenceId' => $paymentData['order_id'],
                'Payload' => [
                    'OrderDetails' => [
                        'Amount' => $paymentData['amount'],
                        'CurrencyCode' => $paymentData['currency'],
                        'OrderNumber' => $paymentData['order_id']
                    ]
                ]
            ];

            $headerEncoded = $this->base64UrlEncode(json_encode($header));
            $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
            
            $signature = hash_hmac(
                'sha256',
                "$headerEncoded.$payloadEncoded",
                $this->config['cardinal']['api_key'],
                true
            );
            
            $signatureEncoded = $this->base64UrlEncode($signature);

            $jwt = "$headerEncoded.$payloadEncoded.$signatureEncoded";

            $this->logger->info('JWT token generated successfully');

            return $jwt;

        } catch (\Exception $e) {
            $this->logger->error('JWT generation failed', [
                'error' => $e->getMessage()
            ]);
            throw new PaymentException('Failed to generate JWT: ' . $e->getMessage());
        }
    }

    private function sendRequest(array $payload): array 
{
    try {
        // Initialize cURL
        $ch = curl_init($this->apiEndpoint);
        if ($ch === false) {
            throw new PaymentException('Failed to initialize cURL');
        }

        // Encode payload
        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new PaymentException('Failed to encode payload: ' . json_last_error_msg());
        }

        // Generate JWT token for authorization
        $jwt = $this->generateJWT($payload['OrderDetails']);

        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $jwt,  // Changed from JWT to Bearer
                'Accept: application/json'
            ],
            CURLOPT_VERBOSE => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30
        ]);

        $this->logger->info('Sending request to Cardinal', [
            'endpoint' => $this->apiEndpoint,
            'payload' => json_decode($jsonPayload, true)  // Log the payload for debugging
        ]);

        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->logger->info('Cardinal API Response', [
            'http_code' => $httpCode,
            'response' => $response
        ]);

        if ($response === false) {
            $error = curl_error($ch);
            throw new PaymentException('Cardinal request failed: ' . $error);
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            throw new PaymentException(
                'Cardinal API error: ' . ($errorData['ErrorDescription'] ?? 'Unknown error'),
                $httpCode,
                $errorData
            );
        }

        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PaymentException('Invalid JSON response from Cardinal');
        }

        return $responseData;

    } catch (\Exception $e) {
        $this->logger->error('Request failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
}

    private function base64UrlEncode(string $data): string 
    {
        $base64 = base64_encode($data);
        $base64Url = strtr($base64, '+/', '-_');
        return rtrim($base64Url, '=');
    }
}