<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /**
     * @param string $level
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        LogRecords::add((string) $level, (string) $message);
    }
}
