<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// A local branch against the base branch is the pull request, as in LocalGitForge, so a branch has a change unless it is the base branch itself.
final readonly class LocalForgeBranches implements ForgeBranches
{
    private const string HEADS = 'refs/heads/';
    private const int TIMEOUT_SECONDS = 60;
    private const int NOT_AN_ANCESTOR = 1;
    private const int NOT_SYMBOLIC = 1;
    private const int MISSING = 2;
    private const string BEING_ADDED = 'locked initializing';
    private const string LISTING_FORMAT = '%(refname)%00%(objectname)%00%(objecttype)%00%(symref)';

    public function __construct(
        private LocalRepository $repository,
        private HardenedGit $hardened = new HardenedGit(),
        private WorktreeLocks $locks = new WorktreeLocks(),
        private RebasesAndBisects $rebases = new RebasesAndBisects(),
    ) {
    }

    public function branches(): array
    {
        $common = $this->commonDirectory();
        $lines = array_filter(explode("\n", $this->read(['for-each-ref', '--format='.self::LISTING_FORMAT, self::HEADS])), static fn (string $line): bool => '' !== $line);
        $branches = array_values(array_map($this->branchFrom(...), $lines));

        $this->refuseLeftOutBranches($common, $branches);

        return $branches;
    }

    public function changeOf(ForgeBranch $branch): ?BranchChange
    {
        if ($branch->name === $this->repository->baseBranch) {
            return null;
        }

        $merged = $this->isAncestor($branch->head, $this->baseHead());

        // A local repository has no closed changes and no labels, so the change is open until the base branch holds its head.
        return new BranchChange($merged ? BranchChangeState::Merged : BranchChangeState::Open);
    }

    public function hasCommitByAPerson(ForgeBranch $branch, string $intaktIdentity): bool
    {
        if ('' === trim($intaktIdentity)) {
            throw new ForgeReadFailed('Intakt\'s identity is empty, so every commit would count as a person\'s.');
        }

        $intakt = mb_strtolower(trim($intaktIdentity));

        return array_any($this->committersOnlyOn($branch), static fn (string $email): bool => mb_strtolower($email) !== $intakt);
    }

    public function delete(ForgeBranch $branch): void
    {
        try {
            $this->deleteAtHead($branch);
        } catch (ForgeReadFailed $failed) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" was not deleted: %s', $branch->name, $failed->getMessage()), $failed->getCode(), $failed);
        }
    }

    // Only a files repository, because both the listing check and the delete's HEAD locks read the ref files.
    private function commonDirectory(): string
    {
        if ('files' !== trim($this->read(['rev-parse', '--show-ref-format']))) {
            throw new ForgeReadFailed('the repository does not keep its refs in files, so its branches cannot be read completely nor deleted safely.');
        }

        return rtrim($this->read(['rev-parse', '--path-format=absolute', '--git-common-dir']), "\n");
    }

    /**
     * @param list<string> $arguments
     */
    private function read(array $arguments): string
    {
        $process = $this->git($arguments);

        if (!$process->isSuccessful()) {
            throw new ForgeReadFailed(sprintf('git %s failed: %s', $arguments[0], trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, ?string $gitDirectory = null): Process
    {
        // An argument with a NUL byte cannot reach git, because PHP refuses to start the process with it.
        if (array_any($arguments, static fn (string $argument): bool => str_contains($argument, "\0"))) {
            throw new ForgeReadFailed(sprintf('git %s was given an argument with a NUL byte.', $arguments[0]));
        }

        try {
            $call = null === $gitDirectory
                ? $this->hardened->call($this->repository->path, ...$arguments)
                : $this->hardened->callInBareRepository($gitDirectory, ...$arguments);
            $process = new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused $refused) {
            throw new ForgeReadFailed($refused->getMessage(), $refused->getCode(), previous: $refused);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('git %s did not run: %s', $arguments[0], $failure->getMessage()), $failure->getCode(), previous: $failure);
        }

        return $process;
    }

    private function branchFrom(string $line): ForgeBranch
    {
        $fields = explode("\0", $line);

        if (4 !== count($fields) || !str_starts_with($fields[0], self::HEADS) || 'commit' !== $fields[2]) {
            throw new ForgeReadFailed(sprintf('git for-each-ref listed something other than a branch at a commit: %s', $fields[0]));
        }

        $name = substr($fields[0], strlen(self::HEADS));

        // A local repository protects no branch, so the base branch and the release names are what cleanup must keep.
        return new ForgeBranch($name, $this->commitFrom($fields[1]), $name === $this->repository->baseBranch, false, '' !== $fields[3]);
    }

    /**
     * @param list<ForgeBranch> $branches
     */
    // A files repository keeps every symbolic branch as a loose file, and for-each-ref leaves out one that dangles or does not parse without failing, so a loose branch missing from the listing makes the listing incomplete.
    private function refuseLeftOutBranches(string $common, array $branches): void
    {
        $directory = $common.'/'.self::HEADS;
        if (!is_dir($directory)) {
            return;
        }

        $listed = array_map(static fn (ForgeBranch $branch): string => $branch->name, $branches);
        foreach (Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($directory) as $file) {
            $name = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            // A .lock file is skipped, because it is git holding a branch while it writes it, and git allows no branch name that ends in .lock.
            if (!str_ends_with($name, '.lock') && !in_array($name, $listed, true)) {
                throw new ForgeReadFailed(sprintf('git for-each-ref left out the branch "%s", which dangles or cannot be read.', $name));
            }
        }
    }

    // The exact branch, because a revision such as refs/heads/<base> falls back to a tag of that name when the branch is missing; and the whole history, because a shallow boundary hides commits and ancestry.
    private function baseHead(): CommitId
    {
        if ('false' !== trim($this->read(['rev-parse', '--is-shallow-repository']))) {
            throw new ForgeReadFailed('the repository\'s history is shallow, so the commits of a branch cannot all be read.');
        }

        return $this->commitFrom(trim($this->read(['show-ref', '--verify', '--hash', self::HEADS.$this->repository->baseBranch])));
    }

    private function isAncestor(CommitId $ancestor, CommitId $descendant): bool
    {
        $process = $this->git(['merge-base', '--is-ancestor', $ancestor->hash, $descendant->hash]);

        return match ($process->getExitCode()) {
            0 => true,
            self::NOT_AN_ANCESTOR => false,
            default => throw new ForgeReadFailed(sprintf('git merge-base failed: %s', trim($process->getErrorOutput()))),
        };
    }

    /**
     * @return list<string>
     */
    // The author and the committer of every commit the base branch does not hold, because either one being a person claims the branch.
    private function committersOnlyOn(ForgeBranch $branch): array
    {
        $output = $this->read(['log', '-z', '--format=%ae%x00%ce', $branch->head->hash, '--not', $this->baseHead()->hash, '--']);
        if ('' === $output) {
            return [];
        }

        // The last NUL is cut, because -z ends the last commit with one too and it would read as an empty email.
        $emails = explode("\0", str_ends_with($output, "\0") ? substr($output, 0, -1) : $output);

        if (0 !== count($emails) % 2 || in_array('', $emails, true)) {
            throw new ForgeReadFailed(sprintf('A commit on the branch "%s" has no author or committer email, so whose it is cannot be told.', $branch->name));
        }

        return $emails;
    }

    // Every guard is read again here, because the listing may be old and a caller may hand in a branch it built itself.
    private function deleteAtHead(ForgeBranch $branch): void
    {
        $reference = self::HEADS.$branch->name;

        if (!$branch->isDeletable() || $branch->name === $this->repository->baseBranch || ForgeBranch::isReleaseName($branch->name)) {
            throw new ForgeReadFailed('it is the default, a protected, a release or a symbolic branch.');
        }

        $common = $this->commonDirectory();
        // Held across the checks and the delete, so no checkout can name the branch in between.
        $locks = $this->locks->take($common);
        try {
            if ($this->isSymbolic($reference)) {
                throw new ForgeReadFailed('it is a symbolic branch now.');
            }

            if (in_array($reference, $this->checkedOut($common), true)) {
                throw new ForgeReadFailed('it is checked out.');
            }

            // --no-deref, so a branch that became symbolic since the check above loses only its own ref, never the branch it names.
            $this->read(['update-ref', '-d', '--no-deref', $reference, $branch->head->hash]);
        } finally {
            $this->locks->release($locks);
        }

        $this->putBackWhenCheckedOutMeanwhile($common, $reference, $branch);
    }

    private function isSymbolic(string $reference): bool
    {
        $process = $this->git(['symbolic-ref', '--quiet', $reference]);

        return match ($process->getExitCode()) {
            0 => true,
            self::NOT_SYMBOLIC => false,
            default => throw new ForgeReadFailed(sprintf('git symbolic-ref failed: %s', trim($process->getErrorOutput()))),
        };
    }

    /**
     * @return list<string>
     */
    // Every worktree's HEAD, because update-ref deletes a branch another worktree has checked out as readily as the repository's own; and the HEAD in the common directory, because a bare repository lists it without a branch line, also when this forge is opened through one of its linked worktrees.
    private function checkedOut(string $common): array
    {
        $lines = explode("\0", $this->read(['worktree', 'list', '--porcelain', '-z']));

        // git worktree add locks the worktree with this reason until its HEAD is written, so the listing cannot tell yet which branch it will name.
        if (in_array(self::BEING_ADDED, $lines, true)) {
            throw new ForgeReadFailed('a worktree is being added, so the branch it checks out cannot be told yet.');
        }

        $branches = array_values(array_map(
            static fn (string $line): string => substr($line, strlen('branch ')),
            array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'branch ')),
        ));

        $branches = [...$branches, ...$this->rebases->branchesHeldIn($common)];
        $head = $this->git(['symbolic-ref', '--quiet', 'HEAD'], $common);

        return match ($head->getExitCode()) {
            0 => [...$branches, trim($head->getOutput())],
            self::NOT_SYMBOLIC => $branches,
            default => throw new ForgeReadFailed(sprintf('git symbolic-ref failed: %s', trim($head->getErrorOutput()))),
        };
    }

    // A worktree added meanwhile takes no lock this delete holds, so the check is read again. A check that cannot answer counts as checked out, because the branch is already gone here and a refusal must leave it in place.
    private function putBackWhenCheckedOutMeanwhile(string $common, string $reference, ForgeBranch $branch): void
    {
        try {
            if (!in_array($reference, $this->checkedOut($common), true)) {
                return;
            }
            $reason = 'a worktree checked it out while it was deleted';
        } catch (ForgeReadFailed $unread) {
            $reason = sprintf('whether a worktree checked it out while it was deleted could not be read (%s)', $unread->getMessage());
        }

        try {
            // The empty old value creates the branch only while it is still missing, so a branch made again meanwhile is never moved.
            $this->read(['update-ref', '--no-deref', $reference, $branch->head->hash, '']);
        } catch (ForgeReadFailed $lost) {
            throw new ForgeWriteFailed(sprintf('The branch "%s" %s (%s): %s', $branch->name, $this->stateAfterAFailedPutBack($reference, $branch->head), $lost->getMessage(), $reason), $lost->getCode(), $lost);
        }

        throw new ForgeReadFailed($reason.', so it was put back at its head.');
    }

    // Read once more, because another writer may have made the branch again before the put-back; show-ref --exists tells a missing branch from a read that failed, which --verify reports alike.
    private function stateAfterAFailedPutBack(string $reference, CommitId $head): string
    {
        $unreadable = sprintf('was deleted at %s and its current state could not be read', $head->hash);

        try {
            $current = $this->git(['show-ref', '--verify', '--hash', $reference]);
            if ($current->isSuccessful()) {
                $commit = $this->commitFrom(trim($current->getOutput()));

                // The type is read, because a branch made again as a symbolic ref can resolve to a blob or a tree, which is no commit to report.
                return 'commit' === trim($this->read(['cat-file', '-t', $commit->hash]))
                    ? sprintf('was deleted at %s and exists again at %s', $head->hash, $commit->hash)
                    : $unreadable;
            }

            return self::MISSING === $this->git(['show-ref', '--exists', $reference])->getExitCode()
                ? sprintf('is deleted at %s and could not be put back', $head->hash)
                : $unreadable;
        } catch (ForgeReadFailed) {
            return $unreadable;
        }
    }

    private function commitFrom(string $hash): CommitId
    {
        try {
            return new CommitId($hash);
        } catch (CommitIdMalformed $invalid) {
            throw new ForgeReadFailed('git printed something other than a commit hash.', $invalid->getCode(), previous: $invalid);
        }
    }
}
