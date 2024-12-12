<?php
namespace App\Services;

class Logger 
{
    private $logFile;
    private $dateFormat = 'Y-m-d H:i:s';

    public function __construct(string $logFile = 'payment.log') 
    {
        $this->logFile = $logFile;
    }

    public function log(string $level, string $message, array $context = []): void 
    {
        $timestamp = date($this->dateFormat);
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logMessage = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        );
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    public function error(string $message, array $context = []): void 
    {
        $this->log('error', $message, $context);
    }

    public function info(string $message, array $context = []): void 
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void 
    {
        $this->log('warning', $message, $context);
    }

    public function debug(string $message, array $context = []): void 
    {
        $this->log('debug', $message, $context);
    }
}