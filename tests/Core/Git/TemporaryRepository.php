<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final readonly class TemporaryRepository
{
    // Because CI runs as a user without a git identity, and git refuses a merge or a commit without one.
    private const array IDENTITY = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false'];

    public string $path;

    public function __construct(string $objectFormat = 'sha1')
    {
        $this->path = sys_get_temp_dir().'/intakt-forge-'.bin2hex(random_bytes(6));
        mkdir($this->path);
        $this->git('init', '--quiet', '--initial-branch=main', '--object-format='.$objectFormat);
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

    public function commitEmpty(string $subject): string
    {
        return $this->commit($subject);
    }

    public function commitWithASignatureHeader(): string
    {
        // A literal block, because git starts the gpg program for any gpgsig header that looks like one, valid or not.
        $commit = implode("\n", [
            'tree '.$this->git('rev-parse', 'HEAD^{tree}'),
            'parent '.$this->git('rev-parse', 'HEAD'),
            'author Test <test@example.invalid> 1700000000 +0000',
            'committer Test <test@example.invalid> 1700000000 +0000',
            'gpgsig -----BEGIN PGP SIGNATURE-----',
            ' ',
            ' iQEzBAABCAAdFiEEnotarealsignature',
            ' -----END PGP SIGNATURE-----',
            '',
            'Signed',
            '',
        ]);
        $hash = $this->run(['hash-object', '-t', 'commit', '-w', '--stdin', '--literally'], $commit);
        $this->git('update-ref', 'HEAD', $hash);

        return $hash;
    }

    public function diff(string $base, string $head): string
    {
        return $this->git('diff', $base, $head);
    }

    public function log(string ...$options): string
    {
        return $this->git('log', ...$options);
    }

    public function configure(string $key, string $value): void
    {
        $this->git('config', $key, $value);
    }

    public function promiseMissingObjectsFrom(string $command): void
    {
        // Because a partial clone fetches a missing object lazily from its promisor remote, a command behind an ext:: URL shows whether a call fetches.
        $this->configure('core.repositoryFormatVersion', '1');
        $this->configure('extensions.partialClone', 'origin');
        $this->configure('remote.origin.url', 'ext::'.$command);
        $this->configure('remote.origin.promisor', 'true');
        $this->configure('remote.origin.partialCloneFilter', 'blob:none');
        $this->configure('protocol.ext.allow', 'always');
    }

    public function removeObject(string $hash): void
    {
        unlink($this->path.'/.git/objects/'.substr($hash, 0, 2).'/'.substr($hash, 2));
    }

    public function writeHook(string $name, string $script): string
    {
        $path = $this->path.'/.git/hooks/'.$name;
        file_put_contents($path, $script);
        chmod($path, 0o755);

        return $path;
    }

    public function checkoutNewBranch(string $name): void
    {
        $this->git('checkout', '--quiet', '-b', $name);
    }

    public function checkout(string $name): void
    {
        $this->git('checkout', '--quiet', $name);
    }

    public function checkoutDetached(string $revision): void
    {
        $this->git('checkout', '--quiet', '--detach', $revision);
    }

    /**
     * @return list<string>
     */
    public function worktrees(): array
    {
        return array_values(array_filter(explode("\n", $this->git('worktree', 'list', '--porcelain')), static fn (string $line): bool => str_starts_with($line, 'worktree ')));
    }

    public function merge(string $branch): string
    {
        $this->git(...self::IDENTITY, ...['merge', '--quiet', '--no-ff', '--no-edit', $branch]);

        return $this->git('rev-parse', 'HEAD');
    }

    // Conflicts resolve to this side, so the same helper writes a resolved conflict and an edit hidden in a merge.
    public function mergeChanging(string $branch, string $path, string $content): string
    {
        $this->git(...self::IDENTITY, ...['merge', '--quiet', '--no-ff', '--no-commit', '--strategy-option=ours', $branch]);
        file_put_contents($this->path.'/'.$path, $content);
        $this->git('add', '--all');

        return $this->commit('Merge '.$branch);
    }

    public function tag(string $name): void
    {
        $this->git('tag', $name);
    }

    public function objectAt(string $revision): string
    {
        return $this->git('rev-parse', $revision);
    }

    public function replace(string $object, string $replacement): void
    {
        $this->git('replace', $object, $replacement);
    }

    // A copy costs no git call, so a test class can build its history once and hand each changing case its own.
    public function copy(): self
    {
        $copy = clone($this, ['path' => sys_get_temp_dir().'/intakt-forge-'.bin2hex(random_bytes(6))]);
        new Filesystem()->mirror($this->path, $copy->path);

        return $copy;
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }

    private function commit(string $message): string
    {
        $this->git(...self::IDENTITY, ...['commit', '--quiet', '--allow-empty', '-m', $message]);

        return $this->git('rev-parse', 'HEAD');
    }

    private function git(string ...$arguments): string
    {
        return $this->run(array_values($arguments), null);
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments, ?string $input): string
    {
        // Inherited GIT_DIR and the like (as inside a git hook) would make the test change another repository.
        $unset = [];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $unset[$name] = false;
            }
        }
        // Without fsync, because the repository is scratch that nothing reads after a crash, and side by side workers otherwise wait on each other's disk flushes.
        $process = new Process(['git', '-c', 'core.fsync=none', ...$arguments], $this->path, $unset, $input);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
