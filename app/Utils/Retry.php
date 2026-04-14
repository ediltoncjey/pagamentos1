<?php

declare(strict_types=1);

namespace App\Utils;

use Throwable;

final class Retry
{
    /**
     * @template T
     * @param callable(int):T $callback
     * @param callable(Throwable, int):void|null $onRetry
     * @return T
     */
    public function execute(
        callable $callback,
        int $attempts = 3,
        int $baseDelayMs = 250,
        float $factor = 2.0,
        ?callable $onRetry = null
    ): mixed {
        $attempt = 1;
        beginning:
        try {
            return $callback($attempt);
        } catch (Throwable $exception) {
            if ($attempt >= $attempts) {
                throw $exception;
            }

            if ($onRetry !== null) {
                $onRetry($exception, $attempt);
            }

            $delayMs = (int) ($baseDelayMs * ($factor ** ($attempt - 1)));
            $jitter = random_int(20, 120);
            usleep(($delayMs + $jitter) * 1000);
            $attempt++;
            goto beginning;
        }
    }
}
