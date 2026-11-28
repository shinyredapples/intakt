<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ReadBuildUsage;

use LogicException;

// A stream wrapper over a real file that puts a link or a named pipe in the file's place when it is opened, after the command's check has seen a regular file, because that order cannot be forced on a plain path.
final class SwappedLog
{
    public const string PROTOCOL = 'intakt-swapped-log';

    public const string LINK = 'link';

    public const string PIPE = 'pipe';

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $file;

    public function stream_open(string $path, string $mode): bool
    {
        $file = $this->fileOf($path);
        unlink($file);

        if (self::PIPE === $this->option('into') ? !$this->pipeOpensWithoutWaiting($file, $mode) : !$this->linked($file)) {
            return false;
        }

        $opened = fopen($file, $mode);
        $this->file = false === $opened ? null : $opened;

        return null !== $this->file;
    }

    public function stream_read(int $count): string
    {
        return (string) fread($this->handle(), max(1, $count));
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
    public function url_stat(string $path, int $flags): array|false
    {
        $file = $this->fileOf($path);

        return 0 !== ($flags & STREAM_URL_STAT_LINK) ? @lstat($file) : @stat($file);
    }

    private function fileOf(string $path): string
    {
        return substr($path, strlen(self::PROTOCOL.'://'));
    }

    private function option(string $name): mixed
    {
        /** @var array<string, array<string, mixed>> $options */
        $options = stream_context_get_options($this->context ?? stream_context_get_default());

        return $options[self::PROTOCOL][$name] ?? throw new LogicException(sprintf('No "%s" in the stream context.', $name));
    }

    // A blocking open of a pipe nobody writes would hang the test instead of failing it, so the attempt is recorded and refused.
    private function pipeOpensWithoutWaiting(string $file, string $mode): bool
    {
        posix_mkfifo($file, 0o600);

        if (str_contains($mode, 'n')) {
            return true;
        }

        $waits = $this->option('waits');
        $waits instanceof ReadBytes ? $waits->add(1) : throw new LogicException('No wait counter in the stream context.');

        return false;
    }

    private function linked(string $file): bool
    {
        $target = $this->option('target');

        return symlink(is_string($target) ? $target : throw new LogicException('No link target in the stream context.'), $file);
    }

    /**
     * @return resource
     */
    private function handle()
    {
        return $this->file ?? throw new LogicException('The log is not open.');
    }
}
