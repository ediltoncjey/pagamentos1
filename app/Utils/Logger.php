<?php

declare(strict_types=1);

namespace App\Utils;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

final class Logger
{
    private ?MonologLogger $logger = null;

    public function __construct(
        private readonly string $channel = 'app',
        private readonly string $path = '',
    ) {
        if (class_exists(MonologLogger::class) && class_exists(StreamHandler::class)) {
            $this->logger = new MonologLogger($this->channel);
            $this->logger->pushHandler(new StreamHandler($this->path !== '' ? $this->path : 'php://stderr'));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context = []): void
    {
        if ($this->logger instanceof MonologLogger) {
            $this->logger->log($level, $message, $context);
            return;
        }

        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $targetPath = $this->path !== '' ? $this->path : 'php://stderr';
        file_put_contents($targetPath, $line . PHP_EOL, FILE_APPEND);
    }
}
