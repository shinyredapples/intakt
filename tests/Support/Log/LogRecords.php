<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Psr\Log\LogLevel;

/**
 * @phpstan-type Record array{level: string, message: string}
 */
final class LogRecords
{
    private const array ERROR_AND_ABOVE = [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    /** @var list<Record> */
    private static array $records = [];

    // Static because the kernel is rebooted between tests and a PHPUnit extension has no container to ask.
    public static function add(string $level, string $message): void
    {
        self::$records[] = ['level' => $level, 'message' => $message];
    }

    public static function remove(string $level, string $message): bool
    {
        foreach (self::$records as $index => $record) {
            // Symfony ends an uncaught exception's message with its source line, which a test must not pin.
            $logged = preg_replace('/ at \S+ line \d+$/', '', $record['message']);
            if ($record['level'] === $level && $logged === $message) {
                array_splice(self::$records, $index, 1);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Record>
     */
    public static function takeErrors(): array
    {
        $errors = array_values(array_filter(self::$records, static fn (array $record): bool => in_array($record['level'], self::ERROR_AND_ABOVE, true)));
        self::$records = [];

        return $errors;
    }
}
