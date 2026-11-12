<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Two repositories, because the check reads a bare mirror while the bundle comes from a build clone.
final readonly class BuildHistory
{
    // Because CI runs as a user without a git identity, and git refuses a commit or a merge without one.
    private const array IDENTITY = ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.fsync=none'];

    public string $folder;

    public string $mirror;

    public string $work;

    public function __construct()
    {
        $this->folder = sys_get_temp_dir().'/intakt-hand-back-'.bin2hex(random_bytes(6));
        $this->mirror = $this->folder.'/mirror.git';
        $this->work = $this->folder.'/work';
        mkdir($this->work, recursive: true);
        $this->git('init', '--quiet', '--initial-branch=main');
        $this->commit('Start');
        $this->run(['clone', '--quiet', '--bare', $this->work, $this->mirror], $this->folder);
        $this->git('checkout', '--quiet', '-b', 'task');
    }

    public function commit(string $message): string
    {
        $this->git('commit', '--quiet', '--allow-empty', '-m', $message);

        return $this->git('rev-parse', 'HEAD');
    }

    public function commitWithASignatureHeader(string $message): string
    {
        // A literal block, because a test has no signing key; nothing here verifies the signature.
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
            $message,
        ]);
        $hash = $this->run(['hash-object', '-t', 'commit', '-w', '--stdin', '--literally'], $this->work, $commit."\n");
        $this->git('update-ref', 'HEAD', $hash);

        return $hash;
    }

    public function commitWithoutAnAuthor(string $message): string
    {
        // Literally, because git itself never writes a commit without an author; only a crafted bundle holds one.
        $commit = implode("\n", [
            'tree '.$this->git('rev-parse', 'HEAD^{tree}'),
            'parent '.$this->git('rev-parse', 'HEAD'),
            'committer Test <test@example.invalid> 1700000000 +0000',
            '',
            $message,
        ]);
        $hash = $this->run(['hash-object', '-t', 'commit', '-w', '--stdin', '--literally'], $this->work, $commit."\n");
        $this->git('update-ref', 'HEAD', $hash);

        return $hash;
    }

    public function checkout(string $branch): void
    {
        $this->git('checkout', '--quiet', $branch);
    }

    public function startBranch(string $branch, string $at): void
    {
        $this->git('checkout', '--quiet', '-B', $branch, $at);
    }

    public function merge(string $branch): string
    {
        $this->git('merge', '--quiet', '--no-ff', '--no-edit', $branch);

        return $this->git('rev-parse', 'HEAD');
    }

    public function push(string $branch): void
    {
        $this->git('push', '--quiet', $this->mirror, $branch);
    }

    public function bundle(string ...$revisions): string
    {
        $path = $this->folder.'/'.bin2hex(random_bytes(4)).'.bundle';
        $this->git('bundle', 'create', '--quiet', $path, ...$revisions);

        return $path;
    }

    public function file(string $content): string
    {
        $path = $this->folder.'/'.bin2hex(random_bytes(4)).'.bundle';
        file_put_contents($path, $content);

        return $path;
    }

    public function inMirror(string ...$arguments): string
    {
        return $this->run(['--git-dir='.$this->mirror, ...array_values($arguments)], $this->folder);
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function git(string ...$arguments): string
    {
        return $this->run(array_values($arguments), $this->work);
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments, string $directory, ?string $input = null): string
    {
        // Inherited GIT_DIR and the like (as inside a git hook) would make the test change another repository.
        $unset = [];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $unset[$name] = false;
            }
        }
        $process = new Process(['git', ...self::IDENTITY, ...$arguments], $directory, $unset, $input);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
