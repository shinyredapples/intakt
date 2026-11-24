<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure\Repository;

use LogicException;

// A stream wrapper that counts its opens, because whether a read took its shortcut shows only in how often it opened the file; the count lives in the stream context, since PHP builds a new wrapper for every open.
final class CountedBytes
{
    public const string PROTOCOL = 'intakt-counted-bytes';
    public const string EARLY_NUL = self::PROTOCOL.'://early-nul';
    public const string LATE_NUL = self::PROTOCOL.'://late-nul';
    public const int SIZE = 100_002;

    /** @var resource|null */
    public $context;

    private string $bytes = '';
    private int $position = 0;

    public function stream_open(string $path): bool
    {
        $this->bytes = $this->bytesAt($path);
        $this->opens()->count();

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->bytes, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->bytes);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return $this->statOf($this->bytes);
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path): array
    {
        return $this->statOf($this->bytesAt($path));
    }

    private function bytesAt(string $path): string
    {
        return match ($path) {
            self::EARLY_NUL => "\0".str_repeat('a', self::SIZE - 1),
            self::LATE_NUL => str_repeat('a', self::SIZE - 1)."\0",
        };
    }

    private function opens(): Opens
    {
        $options = stream_context_get_options($this->context ?? stream_context_get_default());
        $opens = $options[self::PROTOCOL]['opens'] ?? null;

        return $opens instanceof Opens ? $opens : throw new LogicException('No open counter in the stream context.');
    }

    /**
     * @return array<string, int>
     */
    private function statOf(string $bytes): array
    {
        return ['mode' => 0100644, 'size' => strlen($bytes)];
    }
}
