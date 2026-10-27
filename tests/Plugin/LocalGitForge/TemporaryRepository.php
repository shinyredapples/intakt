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

        return $this->commit('Change '.$path);
    }

    public function removeFile(string $path): string
    {
        unlink($this->path.'/'.$path);
        $this->git('add', '--all');

        return $this->commit('Remove '.$path);
    }

    public function commitSubmodule(string $path, string $commit): string
    {
        $this->git('update-index', '--add', '--cacheinfo', '160000,'.$commit.','.$path);

        return $this->commit('Point '.$path.' at '.$commit);
    }

    public function configure(string $key, string $value): void
    {
        $this->git('config', $key, $value);
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

    private function commit(string $message): string
    {
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', $message);

        return $this->git('rev-parse', 'HEAD');
    }

    private function git(string ...$arguments): string
    {
        // Inherited GIT_DIR and the like (as inside a git hook) would make the test change another repository.
        $gitVariables = array_filter(array_keys([...getenv(), ...$_ENV]), static fn (int|string $name): bool => str_starts_with((string) $name, 'GIT_'));
        $process = new Process(['git', ...$arguments], $this->path, array_fill_keys($gitVariables, false));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
