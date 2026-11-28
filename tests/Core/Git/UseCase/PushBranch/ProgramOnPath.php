<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

// A program first on PATH that is killed, or hangs, on one argument and hands every other call to the real one, because a process failure has to be forced at one exact call instead of waited for.
final readonly class ProgramOnPath
{
    private string $folder;
    private string $path;

    public function __construct()
    {
        $this->folder = sys_get_temp_dir().'/intakt-path-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->path = (string) getenv('PATH');
        $this->search($this->folder.':'.$this->path);
    }

    public function killOn(string $program, string $argument): void
    {
        $this->standInFor($program, $argument, 'kill -TERM $$');
    }

    public function hangOn(string $program, string $argument): void
    {
        $this->standInFor($program, $argument, 'exec sleep 30');
    }

    // A shell command run before the real program, so another writer's step lands at the exact point between two calls.
    public function runFirstOn(string $program, string $argument, string $command): void
    {
        $this->standInFor($program, $argument, $command);
    }

    // Only the folder of stand-ins, because a program that is not installed is found nowhere.
    public function hideEveryOtherProgram(): void
    {
        $this->search($this->folder);
    }

    public function remove(): void
    {
        $this->search($this->path);
        new Filesystem()->remove($this->folder);
    }

    private function standInFor(string $program, string $argument, string $action): void
    {
        $real = new ExecutableFinder()->find($program) ?? throw new RuntimeException($program.' is not on PATH.');
        new Filesystem()->dumpFile($this->folder.'/'.$program, sprintf(
            "#!/bin/sh\nfor argument in \"\$@\"; do\n  if [ \"\$argument\" = %s ]; then\n    %s\n  fi\ndone\nexec %s \"\$@\"\n",
            escapeshellarg($argument),
            $action,
            escapeshellarg($real),
        ));
        chmod($this->folder.'/'.$program, 0o755);
    }

    private function search(string $path): void
    {
        putenv('PATH='.$path);
        // Because Symfony Process gives a child $_ENV ahead of the process environment.
        if (array_key_exists('PATH', $_ENV)) {
            $_ENV['PATH'] = $path;
        }
    }
}
