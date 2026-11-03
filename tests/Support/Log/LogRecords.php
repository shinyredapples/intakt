<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Psr\Log\LogLevel;
use RuntimeException;

/**
 * @phpstan-type Record array{level: string, message: string}
 */
final readonly class LogRecords
{
    public const string FILE_VARIABLE = 'INTAKT_TEST_LOG_RECORDS';

    private const array ERROR_AND_ABOVE = [LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    public function __construct(private string $file)
    {
    }

    // A file named in the environment, because the kernel is rebooted between tests and an isolated test runs in its own process, so neither can be handed an object.
    public static function startRun(): self
    {
        $file = tempnam(sys_get_temp_dir(), 'intakt-test-log-');
        if (false === $file) {
            throw new RuntimeException('The test log records file could not be created.');
        }

        putenv(self::FILE_VARIABLE.'='.$file);
        $_ENV[self::FILE_VARIABLE] = $file;
        $_SERVER[self::FILE_VARIABLE] = $file;

        return new self($file);
    }

    public static function ofThisRun(): ?self
    {
        $file = getenv(self::FILE_VARIABLE);

        return false === $file || '' === $file ? null : new self($file);
    }

    public function add(string $level, string $message): void
    {
        file_put_contents($this->file, $this->encoded(['level' => $level, 'message' => $message]), FILE_APPEND | LOCK_EX);
    }

    public function remove(string $level, string $message): bool
    {
        $records = $this->records();
        foreach ($records as $index => $record) {
            // Symfony ends an uncaught exception's message with its source line, which a test must not pin.
            $logged = preg_replace('/ at \S+ line \d+$/', '', $record['message']);
            if ($record['level'] === $level && $logged === $message) {
                array_splice($records, $index, 1);
                $this->write($records);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Record>
     */
    public function takeErrors(): array
    {
        $records = $this->records();
        $this->write([]);

        return array_values(array_filter($records, static fn (array $record): bool => in_array($record['level'], self::ERROR_AND_ABOVE, true)));
    }

    public function discard(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return list<Record>
     */
    private function records(): array
    {
        $lines = is_file($this->file) ? file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

        return array_map($this->decoded(...), false === $lines ? [] : $lines);
    }

    /**
     * @param list<Record> $records
     */
    private function write(array $records): void
    {
        file_put_contents($this->file, implode('', array_map($this->encoded(...), $records)), LOCK_EX);
    }

    /**
     * @param Record $record
     */
    private function encoded(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    }

    /**
     * @return Record
     */
    private function decoded(string $line): array
    {
        /** @var Record $record */
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        return $record;
    }
}
