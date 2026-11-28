<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ReadBuildUsage;

use LogicException;

// A stream wrapper over a real file that grows the file past the byte limit when it is opened, after the command's checks have seen it small, because that order cannot be forced on a plain path.
final class GrowingLog
{
    public const string PROTOCOL = 'intakt-growing-log';

    public const int GROWTH = 5 * 1024 * 1024;

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $file;

    public function stream_open(string $path): bool
    {
        $file = $this->fileOf($path);
        file_put_contents($file, str_repeat("x\n", intdiv(self::GROWTH, 2)), FILE_APPEND);
        $opened = fopen($file, 'rb');
        $this->file = false === $opened ? null : $opened;

        return null !== $this->file;
    }

    public function stream_read(int $count): string
    {
        $chunk = (string) fread($this->handle(), max(1, $count));
        $this->readBytes()->add(strlen($chunk));

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return feof($this->handle());
    }

    public function stream_close(): void
    {
        fclose($this->handle());
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        return fstat($this->handle());
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path): array|false
    {
        return @stat($this->fileOf($path));
    }

    private function fileOf(string $path): string
    {
        return substr($path, strlen(self::PROTOCOL.'://'));
    }

    /**
     * @return resource
     */
    private function handle()
    {
        return $this->file ?? throw new LogicException('The log is not open.');
    }

    private function readBytes(): ReadBytes
    {
        /** @var array<string, array<string, mixed>> $options */
        $options = stream_context_get_options($this->context ?? stream_context_get_default());
        $read = $options[self::PROTOCOL]['read'] ?? null;

        return $read instanceof ReadBytes ? $read : throw new LogicException('No byte counter in the stream context.');
    }
}
