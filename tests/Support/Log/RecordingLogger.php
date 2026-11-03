<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        LogRecords::add(is_string($level) ? $level : get_debug_type($level), (string) $message);
    }
}
