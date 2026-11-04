<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    public function __construct(private readonly LogRecords $records)
    {
    }

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records->add(is_string($level) ? $level : get_debug_type($level), $this->filledIn((string) $message, $context));
    }

    /**
     * @param array<mixed> $context
     */
    private function filledIn(string $message, array $context): string
    {
        // Like Symfony's logger: only scalar and Stringable values fill a placeholder, so the record shows the text that was logged.
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
