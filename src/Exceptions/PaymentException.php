<?php
namespace App\Exceptions;

class PaymentException extends \Exception 
{
    protected $errorData;

    public function __construct(string $message = "", int $code = 0, ?array $errorData = null) 
    {
        parent::__construct($message, $code);
        $this->errorData = $errorData ?? [];
    }

    public function getErrorData(): array 
    {
        return $this->errorData;
    }
}