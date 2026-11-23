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

    public function commitFile(string $path, string $content, string $message): string
    {
        new Filesystem()->dumpFile($this->work.'/'.$path, $content);
        $this->git('add', $path);

        return $this->commit($message);
    }

    public function commitLink(string $path, string $target, string $message): string
    {
        new Filesystem()->symlink($target, $this->work.'/'.$path);
        $this->git('add', $path);

        return $this->commit($message);
    }

    public function removeFile(string $path, string $message): string
    {
        $this->git('rm', '--quiet', '--cached', '--', $path);
        new Filesystem()->remove($this->work.'/'.$path);

        return $this->commit($message);
    }

    public function moveFile(string $from, string $to, string $message): string
    {
        $this->git('mv', '--', $from, $to);

        return $this->commit($message);
    }

    public function commitSubmodule(string $path, string $commit): string
    {
        $this->git('update-index', '--add', '--cacheinfo', '160000,'.$commit.','.$path);

        return $this->commit('Point '.$path.' at '.$commit);
    }

    public function commitByAuthor(string $author, string $message): string
    {
        $this->git('commit', '--quiet', '--allow-empty', '--author='.$author, '-m', $message);

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

    public function commitWithAMissingTree(string $message): string
    {
        return $this->commitOnTree(str_repeat('a', 40), $message);
    }

    public function commitWithAMissingFile(string $message): string
    {
        $tree = $this->run(['mktree', '--missing'], $this->work, '100644 blob '.str_repeat('b', 40)."\tfile\n");

        return $this->commitOnTree($tree, $message);
    }

    // By hand, because "git bundle create" refuses a commit whose objects are missing; only a crafted bundle leaves them out.
    public function bundleOfTheHeadCommitAlone(): string
    {
        $head = $this->git('rev-parse', 'HEAD');
        $pack = $this->output(['pack-objects', '--stdout'], $this->work, $head."\n");

        return $this->file("# v2 git bundle\n-".$this->git('rev-parse', 'HEAD^')."\n".$head." refs/heads/task\n\n".$pack);
    }

    // By hand, because "git bundle create" lists every prerequisite and packs every object the head needs; only a crafted bundle leaves one out.
    /**
     * @param list<string> $prerequisites
     */
    public function bundleByHand(string $head, array $prerequisites, string ...$revisions): string
    {
        // With --shallow, because then every excluded commit's objects serve as delta bases, not only those of the head's own ancestors.
        $pack = $this->output(['pack-objects', '--revs', '--thin', '--shallow', '--stdout'], $this->work, implode("\n", $revisions)."\n");
        $listed = implode('', array_map(static fn (string $prerequisite): string => '-'.$prerequisite."\n", $prerequisites));

        return $this->file("# v2 git bundle\n".$listed.$head." refs/heads/task\n\n".$pack);
    }

    public function mirrorHolds(string $object): bool
    {
        try {
            $this->inMirror('cat-file', '-e', $object);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function quarantinesLeftInTheMirror(): array
    {
        $folders = glob($this->mirror.'/objects/incoming-*');

        return false === $folders ? [] : $folders;
    }

    public function restartAsOrphan(string $branch): void
    {
        $this->git('checkout', '--quiet', 'main');
        $this->git('branch', '--quiet', '-D', $branch);
        $this->git('checkout', '--quiet', '--orphan', $branch);
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

    public function startMerge(string $branch): void
    {
        $this->git('merge', '--quiet', '--no-ff', '--no-commit', $branch);
    }

    public function mergeUnrelated(string $branch): string
    {
        $this->git('merge', '--quiet', '--no-ff', '--no-edit', '--allow-unrelated-histories', $branch);

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

    private function commitOnTree(string $tree, string $message): string
    {
        // Literally, because git checks that a commit's tree exists before it writes one.
        $commit = implode("\n", [
            'tree '.$tree,
            'parent '.$this->git('rev-parse', 'HEAD'),
            'author Test <test@example.invalid> 1700000000 +0000',
            'committer Test <test@example.invalid> 1700000000 +0000',
            '',
            $message,
        ]);
        $hash = $this->run(['hash-object', '-t', 'commit', '-w', '--stdin', '--literally'], $this->work, $commit."\n");
        $this->git('update-ref', 'HEAD', $hash);

        return $hash;
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments, string $directory, ?string $input = null): string
    {
        return trim($this->output($arguments, $directory, $input));
    }

    /**
     * @param list<string> $arguments
     */
    private function output(array $arguments, string $directory, ?string $input = null): string
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

        return $process->getOutput();
    }
}
