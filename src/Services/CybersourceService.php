<?php

namespace Services;

use Exceptions\PaymentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CybersourceService
{
    private $config;
    private $logger;
    private $client;

    public function __construct(array $config, LoggerService $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->client = new Client([
            'base_uri' => $this->getApiUrl(),
            'verify' => true
        ]);
    }

    private function getApiUrl(): string
    {
        return $this->config['environment'] === 'production'
            ? 'https://api.cybersource.com'
            : 'https://apitest.cybersource.com';
    }

    private function generateDigest(string $payload): string
    {
        return 'SHA-256=' . base64_encode(hash('sha256', $payload, true));
    }

    private function getSignature(string $resourcePath, string $method, string $payload): string
    {
        $merchantId = $this->config['merchant_id'];
        $date = gmdate("D, d M Y H:i:s \G\M\T");
        $hostName = parse_url($this->getApiUrl(), PHP_URL_HOST);
        $digest = $this->generateDigest($payload);
        
        // Line items for signature generation
        $signatureItems = [
            "host: " . $hostName,
            "date: " . $date,
            "(request-target): " . strtolower($method) . " " . $resourcePath,
            "digest: " . $digest,
            "v-c-merchant-id: " . $merchantId
        ];
        
        $signatureString = implode("\n", $signatureItems);
        $this->logger->log('Signature string', ['string' => $signatureString]);
        
        $decodedKey = base64_decode($this->config['secret_key']);
        $signature = base64_encode(hash_hmac('sha256', $signatureString, $decodedKey, true));
        
        return sprintf(
            'keyid="%s", algorithm="HmacSHA256", headers="host date (request-target) digest v-c-merchant-id", signature="%s"',
            $this->config['key_id'],
            $signature
        );
    }

    public function processPayment(array $paymentData): array
    {
        try {
            $resourcePath = '/pts/v2/payments';
            $method = 'POST';
            
            $payload = $this->buildPaymentRequest($paymentData);
            $jsonPayload = json_encode($payload);
            
            $headers = [
                'v-c-merchant-id' => $this->config['merchant_id'],
                'Date' => gmdate("D, d M Y H:i:s \G\M\T"),
                'Host' => parse_url($this->getApiUrl(), PHP_URL_HOST),
                'Digest' => $this->generateDigest($jsonPayload),
                'Content-Type' => 'application/json',
                'Profile-Id' => $this->config['api_identifier'],
            ];
            
            // Generate and add signature after other headers are set
            $headers['Signature'] = $this->getSignature($resourcePath, $method, $jsonPayload);
            
            $this->logger->log('Sending payment request', [
                'url' => $this->getApiUrl() . $resourcePath,
                'headers' => $headers,
                'payload' => $payload
            ]);

            $response = $this->client->request($method, $resourcePath, [
                'headers' => $headers,
                'json' => $payload,
                'http_errors' => false // This will prevent Guzzle from throwing exceptions on 4xx/5xx responses
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if ($statusCode !== 200 && $statusCode !== 201) {
                $this->logger->log('Payment failed', [
                    'status' => $statusCode,
                    'response' => $responseBody
                ]);
                throw new PaymentException('Payment failed: ' . ($responseBody['message'] ?? 'Unknown error'));
            }

            $this->logger->log('Payment successful', $responseBody);
            return $responseBody;

        } catch (GuzzleException $e) {
            $this->logger->log('Request failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw new PaymentException('Payment processing failed: ' . $e->getMessage());
        }
    }

    private function buildPaymentRequest(array $data): array
    {
        // Log the incoming data for debugging
        $this->logger->log('Building payment request with data', $data);
        
        return [
            'clientReferenceInformation' => [
                'code' => uniqid('REF_')
            ],
            'processingInformation' => [
                'commerceIndicator' => 'internet',
                'paymentSolution' => '001'
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $data['card_number'],
                    'expirationMonth' => $data['expiry_month'],
                    'expirationYear' => $data['expiry_year'],
                    'securityCode' => $data['cvv']
                ]
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'USD'
                ],
                'billTo' => [
                    'firstName' => $data['firstName'],
                    'lastName' => $data['lastName'],
                    'email' => $data['email'],
                    'address1' => $data['address1'],
                    'locality' => $data['locality'],
                    'administrativeArea' => $data['administrativeArea'] ?? '',
                    'postalCode' => $data['postalCode'] ?? '',
                    'country' => $data['country']
                ]
            ],
            'consumerAuthenticationInformation' => [
                'requestorId' => $this->config['org_unit_id'],
                'referenceId' => $data['referenceId'] ?? null,
                'transactionMode' => 'ECOMMERCE'
            ]
        ];
    }
    // public function initiateCruiseAuthentication(array $paymentData): array
    // {
    //     try {
    //         $payload = [
    //             'clientReferenceInformation' => [
    //                 'code' => uniqid('CRUISE_')
    //             ],
    //             'orderInformation' => [
    //                 'amountDetails' => [
    //                     'totalAmount' => $paymentData['amount'],
    //                     'currency' => 'USD'
    //                 ]
    //             ],
    //             'consumerAuthenticationInformation' => [
    //                 'requestorId' => $this->config['org_unit_id'],
    //                 'referenceId' => uniqid(),
    //                 'transactionMode' => 'ECOMMERCE',
    //                 'returnUrl' => $paymentData['returnUrl'] ?? '', // Add this field
    //                 'merchantName' => $this->config['merchant_id']
    //             ]
    //         ];

    //         $response = $this->client->post('risk/v1/authentications', [
    //             'headers' => $this->getHeaders('post', '/risk/v1/authentications', json_encode($payload)),
    //             'json' => $payload
    //         ]);

    //         return json_decode($response->getBody()->getContents(), true);
    //     } catch (GuzzleException $e) {
    //         $this->logger->log('Cruise Authentication Failed', [
    //             'error' => $e->getMessage(),
    //             'code' => $e->getCode()
    //         ]);
    //         throw new PaymentException('Cruise Authentication Failed: ' . $e->getMessage());
    //     }
    // }

    public function initiateCruiseAuthentication(array $paymentData): array
    {
        try {
            $payload = [
                'clientReferenceInformation' => [
                    'code' => uniqid('CRUISE_'),
                ],
                'orderInformation' => [
                    'amountDetails' => [
                        'totalAmount' => $paymentData['amount'],
                        'currency' => $paymentData['currency'] ?? 'USD',
                    ],
                ],
                'consumerAuthenticationInformation' => [
                    'requestorId' => $this->config['org_unit_id'],
                    'referenceId' => uniqid(),
                    'transactionMode' => 'ECOMMERCE',
                    'authenticationPath' => 'BROWSER',
                    'returnUrl' => 'https://localhost:8000/',
                ],
                'deviceInformation' => [
                    'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ],
            ];
    
            $resourcePath = '/pts/v2/authentications';
            $headers = $this->getHeaders('post', $resourcePath, $payload);
    
            $this->logger->log('Cruise Authentication Request', [
                'url' => $this->getApiUrl() . $resourcePath,
                'headers' => $headers,
                'payload' => $payload,
            ]);
    
            $response = $this->client->post($resourcePath, [
                'headers' => $headers,
                'json' => $payload,
            ]);
    
            $result = json_decode($response->getBody()->getContents(), true);
            $this->logger->log('Cruise Authentication Response', $result);
    
            return $result;
        } catch (GuzzleException $e) {
            $this->logger->log('Cruise Authentication Failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw new PaymentException('Cruise Authentication Failed: ' . $e->getMessage());
        }
    }

    private function getHeaders(string $method, string $resourcePath, array $payload = []): array
    {
        $gmtDate = gmdate('D, d M Y H:i:s \G\M\T');
        $hostName = parse_url($this->getApiUrl(), PHP_URL_HOST);

        // Generate digest from payload
        $jsonPayload = !empty($payload) ? json_encode($payload) : '';
        // Generate digest
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $jsonPayload, true));

        // Create signature string
        $signatureString = [
            "host: " . $hostName,
            "date: " . $gmtDate,
            "(request-target): " . strtolower($method) . " " . $resourcePath,
            "digest: " . $digest,
            "v-c-merchant-id: " . $this->config['merchant_id']
        ];

        $signatureBody = implode("\n", $signatureString);

        // Log the signature string for debugging
        $this->logger->log('Signature String', ['string' => $signatureBody]);

        // Generate signature
        $decodedSecret = base64_decode($this->config['secret_key']);
        $signature = base64_encode(hash_hmac('sha256', $signatureBody, $decodedSecret, true));

        // Create signature header
        $signatureHeader = sprintf(
            'keyid="%s", algorithm="HmacSHA256", headers="host date (request-target) digest v-c-merchant-id", signature="%s"',
            $this->config['key_id'],
            $signature
        );

        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Host' => $hostName,
            'Date' => $gmtDate,
            'Digest' => $digest,
            'Signature' => $signatureHeader,
            'v-c-merchant-id' => $this->config['merchant_id']
        ];
    }
}