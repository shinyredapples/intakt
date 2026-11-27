<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Closure;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\AuthorTrust;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\Forge;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use NoDiscard;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class LocalGitForge implements Forge, AuthorTrust
{
    // Named apart from the snapshots withSnapshotOf() removes itself, so the removal after the judge never races a command that removes its own.
    public const string KEPT_SNAPSHOT_PREFIX = 'intakt-kept-review-';

    private const string SNAPSHOT_PREFIX = 'intakt-review-';
    private const int TIMEOUT_SECONDS = 60;
    private const int REFERENCE_MISSING = 2;
    private const int REFERENCE_INVALID = 1;
    private const int NOT_AN_ANCESTOR = 1;

    // Every file is text to git, because an attribute or git's guess from the first bytes would decide what is binary, which BinaryFiles decides from all the bytes of both blobs; submodules are never ignored, because diff.ignoreSubmodules would hide a changed submodule commit.
    private const array DIFF_OPTIONS = ['--no-color', '--no-ext-diff', '--no-textconv', '--text', '--no-renames', '--ignore-submodules=none'];

    // Because a name that is not ASCII is then written in escapes, so a file's header is UTF-8 whatever bytes its name holds.
    private const array QUOTED_NAMES = ['-c', 'core.quotePath=true'];

    // Because a merge attribute that is set picks git's own text merge, while merge.default, even "text", picks a driver the repository's config defines and git would run it.
    private const string BUILTIN_MERGE_ATTRIBUTES = __DIR__.'/builtin-merge.gitattributes';

    // Because the reviewers must read the head's bytes, which a line-ending conversion or a sparse checkout the repository configures would change or leave out.
    private const array SNAPSHOT_AS_COMMITTED = ['-c', 'core.autocrlf=false', '-c', 'core.eol=lf', '-c', 'core.sparseCheckout=false', '-c', 'core.sparseCheckoutCone=false'];

    public function __construct(
        private LocalRepository $repository,
        private NameStatusParser $nameStatus,
        private HardenedGit $hardened = new HardenedGit(),
        private BinaryFiles $binaryFiles = new BinaryFiles(),
    ) {
    }

    public function change(ChangeId $id): Change
    {
        return $this->changeAt($id, $this->headOf($id));
    }

    // The change as it was at a given head, so a caller that checked that head reads it even when the branch moved on or was never fetched under the change's name.
    public function changeAt(ChangeId $id, CommitId $head): Change
    {
        if (!$this->exists($head)) {
            throw new ForgeReadFailed(sprintf('The head %s of change "%s" is not in the local repository.', $head->hash, $id->value));
        }

        $commits = new CommitRange($this->mergeBase($head), $head);

        return new Change($id, $commits, $this->diff($commits));
    }

    #[NoDiscard]
    public function hasHeadMoved(Change $change): bool
    {
        return !$this->headOf($change->id)->equals($change->commits->head);
    }

    // Trusted without a check, because a local branch is the owner's own and no other author can open one.
    #[NoDiscard]
    public function isAuthorTrusted(ChangeId $change): bool
    {
        return true;
    }

    /**
     * @template T
     *
     * @param Closure(string): T $work
     *
     * @return T
     */
    // A detached worktree in a scratch directory, removed after the work, because what is read there must be the head's files whatever happens in the checkout meanwhile.
    #[NoDiscard]
    public function withSnapshotOf(CommitId $head, Closure $work): mixed
    {
        $snapshot = $this->addWorktree($head, self::SNAPSHOT_PREFIX);

        try {
            return $work($snapshot);
        } finally {
            $this->removeSnapshot($snapshot);
        }
    }

    // Kept for a round that runs after the caller returns, because withSnapshotOf() removes the directory when its work ends; the caller removes it with removeSnapshot().
    #[NoDiscard]
    public function keepSnapshotOf(CommitId $head): string
    {
        return $this->addWorktree($head, self::KEPT_SNAPSHOT_PREFIX);
    }

    // Skips a snapshot already gone, because a stopped command removes it before the work returns.
    public function removeSnapshot(string $snapshot): void
    {
        if (is_dir($snapshot)) {
            $this->read(['worktree', 'remove', '--force', $snapshot]);
        }
    }

    public function headMoveSince(CommitId $earlier, Change $change): HeadMove
    {
        $head = $change->commits->head;

        if ($earlier->equals($head)) {
            return new HeadMove(HeadMoveKind::Unmoved, new Paths());
        }

        if (!$this->exists($earlier) || !$this->isAncestor($earlier->hash, $head->hash)) {
            return new HeadMove(HeadMoveKind::Rewritten, new Paths());
        }

        $kind = $this->isCleanMergeOfBase($earlier, $head) ? HeadMoveKind::CleanMergeOfBase : HeadMoveKind::Commits;

        return new HeadMove($kind, $this->pathsBetween(new CommitRange($earlier, $head)));
    }

    public function fileAtHead(Change $change, string $path): FileContent
    {
        return $this->fileAt($change->commits->head, $path);
    }

    public function fileAt(CommitId $commit, string $path): FileContent
    {
        return FileContent::ofBytes($path, $commit, $this->blob($this->blobAt($commit, $path)));
    }

    // A regular file only, because a gitlink or a symbolic link at a page's path holds no page to read.
    public function holdsRegularFileAt(CommitId $commit, string $path): bool
    {
        $entries = explode("\0", $this->read(['ls-tree', '-z', '--full-tree', $commit->hash, '--', $path]));

        return array_any($entries, static fn (string $entry): bool => 1 === preg_match('/^100(?:644|755) blob [0-9a-f]+\t(.*)$/s', $entry, $match) && $match[1] === $path);
    }

    public function baseCommit(): CommitId
    {
        return $this->commitFrom($this->read(['show-ref', '--verify', '--hash', 'refs/heads/'.$this->repository->baseBranch]));
    }

    private function addWorktree(CommitId $head, string $prefix): string
    {
        $directory = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        $this->read([...self::SNAPSHOT_AS_COMMITTED, 'worktree', 'add', '--detach', '--quiet', $directory, $head->hash]);

        // Resolved because a worker started elsewhere reads the stored path from its own working directory.
        $snapshot = realpath($directory);

        return false === $snapshot ? throw new ForgeReadFailed('git worktree add left no directory at '.$directory.'.') : $snapshot;
    }

    private function headOf(ChangeId $id): CommitId
    {
        // An exact ref only, so tags and revision expressions such as "feature~1" are not branches.
        $reference = 'refs/heads/'.$id->value;

        // No branch can carry an invalid name; --branch is not used because it expands shorthands such as "@{-1}".
        $format = $this->git(['check-ref-format', $reference]);
        if (self::REFERENCE_INVALID === $format->getExitCode()) {
            throw ChangeNotFound::named($id);
        }

        if (!$format->isSuccessful()) {
            throw new ForgeReadFailed(sprintf('git check-ref-format failed: %s', trim($format->getErrorOutput())));
        }

        if (self::REFERENCE_MISSING === $this->git(['show-ref', '--exists', $reference])->getExitCode()) {
            throw ChangeNotFound::named($id);
        }

        return $this->commitFrom($this->read(['show-ref', '--verify', '--hash', $reference]));
    }

    private function exists(CommitId $commit): bool
    {
        // Checked first, because a head rewritten and collected away leaves no object behind, and that is a rewrite rather than a failed read.
        return $this->git(['cat-file', '-e', $commit->hash.'^{commit}'])->isSuccessful();
    }

    private function mergeBase(CommitId $head): CommitId
    {
        return $this->commitFrom($this->read(['merge-base', 'refs/heads/'.$this->repository->baseBranch, $head->hash]));
    }

    private function diff(CommitRange $commits): Diff
    {
        // Fixed prefixes, because diff.noprefix or diff.mnemonicPrefix would change the headers the docs part of the diff is cut by.
        $patch = $this->read([...self::QUOTED_NAMES, 'diff', '--full-index', '--src-prefix=a/', '--dst-prefix=b/', ...self::DIFF_OPTIONS, ...$this->range($commits)]);

        return new Diff($this->binaryFiles->named($patch, $this->blob(...)), $this->changedFiles($commits));
    }

    /**
     * @return list<string>
     */
    private function range(CommitRange $commits): array
    {
        return [$commits->base->hash, $commits->head->hash];
    }

    private function changedFiles(CommitRange $commits): ChangedFiles
    {
        $files = $this->nameStatus->parse($this->read(['diff', '--name-status', '-z', ...self::DIFF_OPTIONS, ...$this->range($commits)]));

        // Because -z gives each path's raw bytes while every path reaches prompts and payloads that must be UTF-8; an escaped path could be the valid name of another file, so it is refused.
        foreach ($files as $file) {
            if (!mb_check_encoding($file->path, 'UTF-8')) {
                throw new ForgeReadFailed(sprintf('git listed a changed path that is not valid UTF-8: %s.', $this->binaryFiles->escaped($file->path)));
            }
        }

        return $files;
    }

    private function isAncestor(string $ancestor, string $descendant): bool
    {
        $process = $this->git(['merge-base', '--is-ancestor', $ancestor, $descendant]);

        return match ($process->getExitCode()) {
            0 => true,
            self::NOT_AN_ANCESTOR => false,
            default => throw new ForgeReadFailed(sprintf('git merge-base failed: %s', trim($process->getErrorOutput()))),
        };
    }

    private function isCleanMergeOfBase(CommitId $earlier, CommitId $head): bool
    {
        $parents = explode(' ', trim($this->read(['rev-list', '--parents', '--max-count=1', $head->hash])));

        if (3 !== count($parents) || $parents[1] !== $earlier->hash || !$this->isAncestor($parents[2], 'refs/heads/'.$this->repository->baseBranch)) {
            return false;
        }

        // Clean means the head holds exactly what git merges by itself, so neither a conflict resolution nor an edit hidden in the merge keeps a verdict.
        $merged = $this->git(['-c', 'core.attributesFile='.self::BUILTIN_MERGE_ATTRIBUTES, 'merge-tree', '--write-tree', '--no-messages', $earlier->hash, $parents[2]]);

        return $merged->isSuccessful() && trim($merged->getOutput()) === trim($this->read(['rev-parse', $head->hash.'^{tree}']));
    }

    private function pathsBetween(CommitRange $commits): Paths
    {
        return new Paths(...array_map(static fn (ChangedFile $file): string => $file->path, [...$this->changedFiles($commits)]));
    }

    private function blobAt(CommitId $head, string $path): string
    {
        // ls-tree lists nothing for a missing path but fails when the head cannot be read, which keeps the two apart.
        $entries = explode("\0", $this->read(['ls-tree', '-z', '--full-tree', $head->hash, '--', $path]));

        foreach ($entries as $entry) {
            if (1 === preg_match('/^\d{6} blob ([0-9a-f]{40}|[0-9a-f]{64})\t(.*)$/s', $entry, $match) && $match[2] === $path) {
                return $match[1];
            }
        }

        throw FileNotFoundAtHead::at($path, $head);
    }

    private function blob(string $id): string
    {
        return $this->read(['cat-file', 'blob', $id]);
    }

    private function commitFrom(string $output): CommitId
    {
        try {
            return new CommitId(trim($output));
        } catch (CommitIdMalformed $invalid) {
            throw new ForgeReadFailed('git printed something other than a commit hash.', $invalid->getCode(), previous: $invalid);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function read(array $arguments): string
    {
        $process = $this->git($arguments);

        if (!$process->isSuccessful()) {
            throw new ForgeReadFailed(sprintf('git %s failed: %s', $this->commandOf($arguments), trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): Process
    {
        // An argument with a NUL byte cannot reach git, because PHP refuses to start the process with it.
        if ([] !== array_filter($arguments, static fn (string $argument): bool => str_contains($argument, "\0"))) {
            throw new ForgeReadFailed(sprintf('git %s was given an argument with a NUL byte.', $this->commandOf($arguments)));
        }

        try {
            $call = $this->hardened->call($this->repository->path, ...$arguments);
            $process = new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused $refused) {
            throw new ForgeReadFailed($refused->getMessage(), $refused->getCode(), previous: $refused);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('git %s did not run: %s', $this->commandOf($arguments), $failure->getMessage()), $failure->getCode(), previous: $failure);
        }

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    // The -c settings in front are skipped, so a failure names the git command rather than "-c".
    private function commandOf(array $arguments): string
    {
        $position = 0;
        while ('-c' === ($arguments[$position] ?? null)) {
            $position += 2;
        }

        return $arguments[$position] ?? '';
    }
}
