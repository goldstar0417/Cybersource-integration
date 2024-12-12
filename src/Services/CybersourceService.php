<?php
namespace App\Services;

use App\Exceptions\PaymentException;

class CybersourceService 
{
    private $config;
    private $logger;
    private $cardinalService;
    private $apiEndpoint = 'https://api.cybersource.com';

    public function __construct(array $config, Logger $logger) 
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->cardinalService = new CardinalService($config, $logger);
    }

    public function processPayment(array $paymentData): array 
    {
        try {
            // Step 1: 3DS Authentication
            $threeDSResult = $this->cardinalService->initiate($paymentData);
            
            if ($threeDSResult['Status'] !== 'AUTHENTICATION_SUCCESSFUL') {
                throw new PaymentException('3DS Authentication failed', 0, $threeDSResult);
            }

            // Step 2: Process Payment
            $headers = $this->generateHeaders('post', '/pts/v2/payments');
            $payload = $this->buildPaymentPayload($paymentData, $threeDSResult);

            $response = $this->sendRequest(
                '/pts/v2/payments',
                'POST',
                $headers,
                $payload
            );

            $this->logger->info('Payment processed', [
                'order_id' => $paymentData['order_id'],
                'transaction_id' => $response['id'] ?? null,
                'status' => $response['status'] ?? null
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Payment failed', [
                'error' => $e->getMessage(),
                'order_id' => $paymentData['order_id'] ?? null
            ]);
            throw $e;
        }
    }

    public function processRefund(string $transactionId, array $refundData): array 
    {
        try {
            $path = "/pts/v2/payments/{$transactionId}/refunds";
            $headers = $this->generateHeaders('post', $path);
            $payload = $this->buildRefundPayload($refundData);

            $response = $this->sendRequest($path, 'POST', $headers, $payload);

            $this->logger->info('Refund processed', [
                'transaction_id' => $transactionId,
                'refund_id' => $response['id'] ?? null,
                'status' => $response['status'] ?? null
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Refund failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);
            throw $e;
        }
    }

    public function checkStatus(string $transactionId): array 
    {
        try {
            $path = "/pts/v2/payments/{$transactionId}";
            $headers = $this->generateHeaders('get', $path);
            
            return $this->sendRequest($path, 'GET', $headers);

        } catch (\Exception $e) {
            $this->logger->error('Status check failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);
            throw $e;
        }
    }

    private function generateHeaders(string $method, string $path): array 
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $nonce = uniqid();
        
        $signatureParams = [
            'host' => parse_url($this->apiEndpoint, PHP_URL_HOST),
            'date' => $timestamp,
            'nonce' => $nonce,
            '(request-target)' => $method . ' ' . $path
        ];

        $signatureString = implode('\n', array_map(
            fn($k, $v) => "$k: $v",
            array_keys($signatureParams),
            $signatureParams
        ));

        $signature = base64_encode(
            hash_hmac(
                'sha256',
                $signatureString,
                base64_decode($this->config['merchant']['secret_key']),
                true
            )
        );

        return [
            'v-c-merchant-id' => $this->config['merchant']['mid'],
            'v-c-timestamp' => $timestamp,
            'v-c-nonce' => $nonce,
            'signature' => $signature,
            'Content-Type' => 'application/json'
        ];
    }

    private function buildPaymentPayload(array $paymentData, array $threeDSResult): array 
    {
        return [
            'processingInformation' => [
                'commerceIndicator' => 'internet',
                'paymentSolution' => 'visaCheckout'
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $paymentData['card']['number'],
                    'expirationMonth' => $paymentData['card']['expiry_month'],
                    'expirationYear' => $paymentData['card']['expiry_year'],
                    'securityCode' => $paymentData['card']['cvv']
                ]
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $paymentData['amount'],
                    'currency' => $paymentData['currency']
                ]
            ],
            'consumerAuthenticationInformation' => [
                'cavv' => $threeDSResult['Payment']['ExtendedData']['CAVV'] ?? null,
                'xid' => $threeDSResult['Payment']['ExtendedData']['XID'] ?? null,
                'eciRaw' => $threeDSResult['Payment']['ExtendedData']['ECI'] ?? null
            ]
        ];
    }

    private function buildRefundPayload(array $refundData): array 
    {
        return [
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $refundData['amount'],
                    'currency' => $refundData['currency']
                ]
            ]
        ];
    }

    private function sendRequest(string $path, string $method, array $headers, array $payload = null): array 
    {
        $url = $this->apiEndpoint . $path;
        $ch = curl_init($url);
        
        $curlHeaders = array_map(
            fn($k, $v) => "$k: $v",
            array_keys($headers),
            $headers
        );

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new PaymentException('Request failed: ' . curl_error($ch));
        }

        curl_close($ch);

        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PaymentException('Invalid JSON response');
        }

        if ($httpCode >= 400) {
            throw new PaymentException(
                'API error: ' . ($responseData['message'] ?? 'Unknown error'),
                $httpCode,
                $responseData
            );
        }

        return $responseData;
    }
}