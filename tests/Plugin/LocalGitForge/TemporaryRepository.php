<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final readonly class TemporaryRepository
{
    public string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().'/intakt-forge-'.bin2hex(random_bytes(6));
        mkdir($this->path);
        $this->git('init', '--quiet', '--initial-branch=main');
    }

    public function commitFile(string $path, string $content): string
    {
        $directory = dirname($this->path.'/'.$path);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }
        file_put_contents($this->path.'/'.$path, $content);
        $this->git('add', '--all');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Change '.$path);

        return $this->git('rev-parse', 'HEAD');
    }

    public function removeFile(string $path): string
    {
        unlink($this->path.'/'.$path);
        $this->git('add', '--all');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Remove '.$path);

        return $this->git('rev-parse', 'HEAD');
    }

    public function checkoutNewBranch(string $name): void
    {
        $this->git('checkout', '--quiet', '-b', $name);
    }

    public function checkout(string $name): void
    {
        $this->git('checkout', '--quiet', $name);
    }

    public function tag(string $name): void
    {
        $this->git('tag', $name);
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }

    private function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->path);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
