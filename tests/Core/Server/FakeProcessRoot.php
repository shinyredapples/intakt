<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Symfony\Component\Filesystem\Filesystem;

// A process list laid out as /proc lays it out (one numbered folder per process, its arguments NUL-separated), so no test depends on what runs on the machine.
final readonly class FakeProcessRoot
{
    public string $path;

    /**
     * @param list<list<string>> $commandLines
     */
    public function __construct(array $commandLines)
    {
        $this->path = sys_get_temp_dir().'/intakt-proc-'.bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->mkdir($this->path.'/self');
        foreach ($commandLines as $index => $arguments) {
            $files->dumpFile(sprintf('%s/%d/cmdline', $this->path, 100 + $index), implode("\0", $arguments)."\0");
        }
    }

    public static function healthy(): self
    {
        return new self([
            ['frankenphp', 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'],
            ['php', 'bin/console', 'messenger:consume', 'async', '--memory-limit=512M', '--time-limit=3600', '--quiet'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher', 'scheduler_retention', '--memory-limit=512M', '--time-limit=3600', '--quiet'],
        ]);
    }

    public function remove(): void
    {
        new Filesystem()->chmod($this->path, 0o755);
        new Filesystem()->remove($this->path);
    }
}
