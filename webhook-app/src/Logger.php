<?php

namespace LeadSpace\WebhookApp;

class Logger
{
    private string $logFile;
    private bool $echo;

    public function __construct(string $logFile, bool $echo = true)
    {
        $this->logFile = $logFile;
        $this->echo = $echo;

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . "] {$level}: {$message}";
        file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($this->echo) {
            echo $line . PHP_EOL;
        }
    }
}
