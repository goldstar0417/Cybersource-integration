<?php

namespace Services;

class LoggerService
{
    private $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    public function log(string $message, array $context = []): void
    {
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $message . ' - ' . json_encode($context) . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
}