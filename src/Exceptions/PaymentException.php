<?php
namespace Exceptions;

class PaymentException extends \Exception {
    protected $errorData;

    public function __construct($message, $errorData = [], $code = 0, \Throwable $previous = null) {
        $this->errorData = $errorData;
        parent::__construct($message, $code, $previous);
    }

    public function getErrorData() {
        return $this->errorData;
    }
}