<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use RuntimeException;

// Serves a report whose size says 4 bytes but whose content runs to 2 MiB, as a report the job grew after the size check, and counts what the reader takes from it.
final class GrowingReportStream
{
    public const string PROTOCOL = 'intakt-growing-report';
    public const int GROWN_BYTES = 2 * 1024 * 1024;

    public static int $bytesRead = 0;
    public static ?RuntimeException $failure = null;

    /** @var resource|null */
    public $context;

    public static function register(?RuntimeException $failure = null): void
    {
        self::$bytesRead = 0;
        self::$failure = $failure;
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function unregister(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function url_stat(): array
    {
        return ['mode' => 0o100644, 'size' => 4];
    }

    public function stream_open(): bool
    {
        return true;
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function stream_stat(): array
    {
        return $this->url_stat();
    }

    public function stream_read(int $count): string
    {
        if (self::$failure instanceof RuntimeException) {
            throw self::$failure;
        }

        $count = min($count, self::GROWN_BYTES - self::$bytesRead);
        self::$bytesRead += $count;

        return str_repeat('x', $count);
    }

    public function stream_eof(): bool
    {
        return self::$bytesRead >= self::GROWN_BYTES;
    }
}
