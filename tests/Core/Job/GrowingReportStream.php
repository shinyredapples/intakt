<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use RuntimeException;

// A stream wrapper, because a real file cannot be made to grow between the reader's size check and its read; the size says 4 bytes while the content runs to 2 MiB.
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
