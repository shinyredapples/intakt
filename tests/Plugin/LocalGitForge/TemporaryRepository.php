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
        // A partial clone fetches a missing object lazily from its promisor remote, here a command that git starts through the ext transport.
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

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }

    private function commit(string $message): string
    {
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--allow-empty', '-m', $message);

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
        $process = new Process(['git', ...$arguments], $this->path, $unset, $input);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
