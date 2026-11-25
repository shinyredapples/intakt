<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Infrastructure\ConsumerProcessesCheck;
use Symfony\Component\Filesystem\Filesystem;

// A process list laid out as /proc lays it out (one numbered folder per process, its arguments NUL-separated, its working directory a link), so no test depends on what runs on the machine.
final readonly class FakeProcessRoot
{
    // Stands for the install's folder in an argument, because that folder's name is only known once the root exists.
    public const string INSTALL = '{install}';
    public const string OTHER_INSTALL = '{other-install}';

    public string $path;
    public string $install;
    public string $otherInstall;

    /**
     * @param list<list<string>>      $commandLines
     * @param array<int, string|null> $workingDirectories the working directory of the process at that index when it is not the install: a folder, or null for one that cannot be read
     */
    public function __construct(array $commandLines, array $workingDirectories = [])
    {
        $this->path = sys_get_temp_dir().'/intakt-proc-'.bin2hex(random_bytes(6));
        $this->install = $this->path.'/install';
        $this->otherInstall = $this->path.'/other-install';
        $files = new Filesystem();
        $files->mkdir($this->path.'/self');
        $files->dumpFile($this->install.'/bin/console', '');
        $files->dumpFile($this->otherInstall.'/bin/console', '');
        foreach ($commandLines as $index => $arguments) {
            $folder = sprintf('%s/%d', $this->path, 100 + $index);
            $files->dumpFile($folder.'/cmdline', $this->placed(implode("\0", $arguments))."\0");
            $workingDirectory = array_key_exists($index, $workingDirectories) ? $workingDirectories[$index] : self::INSTALL;
            if (null !== $workingDirectory) {
                $files->symlink($this->placed($workingDirectory), $folder.'/cwd');
            }
        }
    }

    private function placed(string $text): string
    {
        return str_replace([self::INSTALL, self::OTHER_INSTALL], [$this->install, $this->otherInstall], $text);
    }

    public static function healthy(): self
    {
        return new self([
            ['frankenphp', 'run', '--config', 'Caddyfile', '--adapter', 'caddyfile'],
            ['php', 'bin/console', 'messenger:consume', 'async', '--memory-limit=512M', '--time-limit=3600', '--quiet'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher', 'scheduler_retention', '--memory-limit=512M', '--time-limit=3600', '--quiet'],
        ]);
    }

    public function check(): ConsumerProcessesCheck
    {
        return new ConsumerProcessesCheck($this->install, $this->path);
    }

    public function remove(): void
    {
        new Filesystem()->chmod($this->path, 0o755);
        new Filesystem()->remove($this->path);
    }
}
