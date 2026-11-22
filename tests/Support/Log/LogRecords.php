<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Closure;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * @phpstan-type Record array{level: string, message: string, traced?: true}
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

    // Traced marks a record whose context holds an exception, because the service log prints that exception's trace.
    public function add(string $level, string $message, bool $traced = false): void
    {
        $record = ['level' => $level, 'message' => $message];
        if ($traced) {
            $record['traced'] = true;
        }

        file_put_contents($this->file, $this->encoded($record), FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<Record>
     */
    public function all(): array
    {
        return $this->whileLocked($this->records(...));
    }

    public function remove(string $level, string $message): bool
    {
        return $this->whileLocked(function ($handle) use ($level, $message): bool {
            $records = $this->records($handle);
            foreach ($records as $index => $record) {
                // Symfony ends an uncaught exception's message with its source line, which a test must not pin.
                $logged = preg_replace('/ at \S+ line \d+$/', '', $record['message']);
                if ($record['level'] === $level && $logged === $message) {
                    array_splice($records, $index, 1);
                    $this->write($handle, $records);

                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @return list<Record>
     */
    public function takeErrors(): array
    {
        return $this->whileLocked(function ($handle): array {
            $records = $this->records($handle);
            $this->write($handle, []);

            return array_values(array_filter($records, static fn (array $record): bool => in_array($record['level'], self::ERROR_AND_ABOVE, true)));
        });
    }

    public function discard(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<Record>
     */
    private function records($handle): array
    {
        $lines = explode(PHP_EOL, (string) stream_get_contents($handle, offset: 0));

        return array_map($this->decoded(...), array_values(array_filter($lines, static fn (string $line): bool => '' !== $line)));
    }

    /**
     * @template T
     *
     * @param Closure(resource): T $change
     *
     * @return T
     */
    private function whileLocked(Closure $change): mixed
    {
        // One lock from the read to the rewrite, because a record another process appends in between would otherwise be lost.
        $handle = fopen($this->file, 'c+');
        if (false === $handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('The test log records file could not be locked.');
        }

        try {
            return $change($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource     $handle
     * @param list<Record> $records
     */
    private function write($handle, array $records): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, implode('', array_map($this->encoded(...), $records)));
        fflush($handle);
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
