<?php

declare(strict_types=1);

namespace BitrixTelegram\Helpers;

class Logger
{
    private string $logPath;
    private bool $enabled;
    private string $level;

    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    public function __construct(array $config)
    {
        $this->logPath = $config['path'];
        $this->enabled = $config['enabled'];
        $this->level = $config['level'] ?? 'info';

        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0775, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if (self::LEVELS[$level] < self::LEVELS[$this->level]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        $logMessage = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextString
        );

        $filename = sprintf(
            '%s/%s.txt',
            $this->logPath,
            date('Y-m-d')
        );

        file_put_contents($filename, $logMessage, FILE_APPEND);
    }

    public function logException(\Throwable $e, string $context = ''): void
    {
        $this->error(
            sprintf('%s: %s', $context, $e->getMessage()),
            [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]
        );
    }
}