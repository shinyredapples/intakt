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

    public function makeExecutable(string $path): string
    {
        chmod($this->path.'/'.$path, 0o755);
        $this->git('add', '--all');

        return $this->commit('Make '.$path.' executable');
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

    public function removeSubmodule(string $path): string
    {
        $this->git('rm', '--cached', '--quiet', $path);

        return $this->commit('Remove '.$path);
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

    public function commitSignedWith(string $keyFile, string $subject): string
    {
        $this->git(...self::IDENTITY, ...['-c', 'gpg.format=ssh', '-c', 'user.signingkey='.$keyFile, 'commit', '--quiet', '--allow-empty', '-S', '-m', $subject]);

        return $this->git('rev-parse', 'HEAD');
    }

    // Untrimmed, because a signature covers every byte of the object, its final newline too.
    public function rawCommit(string $revision): string
    {
        return $this->bytes(['cat-file', 'commit', $revision], null);
    }

    // Written as given, because a crafted header (a second signature, for example) is what git itself never writes.
    public function commitObject(string $object): string
    {
        $hash = $this->run(['hash-object', '-t', 'commit', '-w', '--stdin', '--literally'], $object);
        $this->git('update-ref', 'HEAD', $hash);

        return $hash;
    }

    public function resetTo(string $revision): void
    {
        $this->git('reset', '--quiet', '--hard', $revision);
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

    public function cutHistoryBelow(string $commit): void
    {
        file_put_contents($this->path.'/.git/shallow', $commit."\n");
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

    public function deleteBranch(string $name): void
    {
        $this->git('branch', '--quiet', '-D', $name);
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

    // Written by hand, because git merge refuses a second parent the branch already holds, which is how a crafted merge hides a revert or a deletion.
    public function craftMerge(string $secondParent, string $path, ?string $content): string
    {
        if (null === $content) {
            unlink($this->path.'/'.$path);
        } else {
            file_put_contents($this->path.'/'.$path, $content);
        }
        $this->git('add', '--all');
        $merge = $this->git(...self::IDENTITY, ...['commit-tree', $this->git('write-tree'), '-p', 'HEAD', '-p', $secondParent, '-m', 'Merge']);
        $this->git('update-ref', 'HEAD', $merge);

        return $merge;
    }

    public function checkoutOrphan(string $name): void
    {
        $this->git('checkout', '--quiet', '--orphan', $name);
        $this->git('rm', '--quiet', '-r', '-f', '.');
    }

    public function tag(string $name): void
    {
        $this->git('tag', $name);
    }

    public function annotatedTag(string $name, string $revision): string
    {
        $this->git(...self::IDENTITY, ...['tag', '--annotate', '--message='.$name, $name, $revision]);

        return $this->objectAt('refs/tags/'.$name);
    }

    public function objectAt(string $revision): string
    {
        return $this->git('rev-parse', $revision);
    }

    public function replace(string $object, string $replacement): void
    {
        $this->git('replace', $object, $replacement);
    }

    public function commitFileAs(string $email, string $path, string $content): string
    {
        file_put_contents($this->path.'/'.$path, $content);
        $this->git('add', '--all');
        $this->git('-c', 'user.name=Someone', '-c', 'user.email='.$email, '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Change '.$path);

        return $this->git('rev-parse', 'HEAD');
    }

    public function aliasBranch(string $alias, string $target): void
    {
        $this->git('symbolic-ref', 'refs/heads/'.$alias, 'refs/heads/'.$target);
    }

    public function addWorktree(string $directory, string $branch): void
    {
        $this->git('worktree', 'add', '--quiet', $directory, $branch);
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
        return trim($this->bytes($arguments, $input));
    }

    /**
     * @param list<string> $arguments
     */
    private function bytes(array $arguments, ?string $input): string
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

        return $process->getOutput();
    }
}
