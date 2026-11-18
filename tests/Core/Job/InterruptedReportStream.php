<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

// A stream wrapper, because a real file cannot be made to fail in the middle of a read; it yields a well-formed report, then warns and fails, so PHP returns the bytes read so far.
final class InterruptedReportStream
{
    public const string PROTOCOL = 'intakt-interrupted-report';
    public const string READ_BEFORE_THE_FAILURE = "end\n";

    public static int $warnings = 0;

    /** @var resource|null */
    public $context;

    private bool $started = false;

    public static function register(): void
    {
        self::$warnings = 0;
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
        return ['mode' => 0o100644, 'size' => strlen(self::READ_BEFORE_THE_FAILURE)];
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

    public function stream_read(): string|false
    {
        if (!$this->started) {
            $this->started = true;

            return self::READ_BEFORE_THE_FAILURE;
        }
        ++self::$warnings;
        trigger_error('report read failed', E_USER_WARNING);

        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }
}
