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
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// A local branch against the base branch is the pull request, as in LocalGitForge, so a branch has a change unless it is the base branch itself.
final readonly class LocalForgeBranches implements ForgeBranches
{
    private const string HEADS = 'refs/heads/';
    private const int TIMEOUT_SECONDS = 60;
    private const int NOT_AN_ANCESTOR = 1;
    private const int NOT_SYMBOLIC = 1;
    private const string LISTING_FORMAT = '%(refname)%00%(objectname)%00%(objecttype)%00%(symref)';

    public function __construct(
        private LocalRepository $repository,
        private HardenedGit $hardened = new HardenedGit(),
    ) {
    }

    public function branches(): array
    {
        $lines = array_filter(explode("\n", $this->read(['for-each-ref', '--format='.self::LISTING_FORMAT, self::HEADS])), static fn (string $line): bool => '' !== $line);

        return array_values(array_map($this->branchFrom(...), $lines));
    }

    public function changeOf(ForgeBranch $branch): ?BranchChange
    {
        if ($branch->name === $this->repository->baseBranch) {
            return null;
        }

        $merged = $this->isAncestor($branch->head, self::HEADS.$this->repository->baseBranch);

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

    private function isAncestor(CommitId $ancestor, string $descendant): bool
    {
        $process = $this->git(['merge-base', '--is-ancestor', $ancestor->hash, $descendant]);

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
        $output = $this->read(['log', '-z', '--format=%ae%x00%ce', $branch->head->hash, '--not', self::HEADS.$this->repository->baseBranch, '--']);
        if ('' === $output) {
            return [];
        }

        // -z ends each commit with a NUL, the last one too.
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

        if ($this->isSymbolic($reference)) {
            throw new ForgeReadFailed('it is a symbolic branch now.');
        }

        if (in_array($reference, $this->checkedOut(), true)) {
            throw new ForgeReadFailed('it is checked out.');
        }

        // --no-deref, so a branch that became symbolic since the check above loses only its own ref, never the branch it names.
        $this->read(['update-ref', '-d', '--no-deref', $reference, $branch->head->hash]);
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
    // The branch every worktree's HEAD names, the repository's own among them.
    private function checkedOut(): array
    {
        $lines = explode("\0", $this->read(['worktree', 'list', '--porcelain', '-z']));

        return array_values(array_map(
            static fn (string $line): string => substr($line, strlen('branch ')),
            array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'branch ')),
        ));
    }

    private function commitFrom(string $hash): CommitId
    {
        try {
            return new CommitId($hash);
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
            throw new ForgeReadFailed(sprintf('git %s failed: %s', $arguments[0], trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): Process
    {
        // An argument with a NUL byte cannot reach git, because PHP refuses to start the process with it.
        if (array_any($arguments, static fn (string $argument): bool => str_contains($argument, "\0"))) {
            throw new ForgeReadFailed(sprintf('git %s was given an argument with a NUL byte.', $arguments[0]));
        }

        try {
            $call = $this->hardened->call($this->repository->path, ...$arguments);
            $process = new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused $refused) {
            throw new ForgeReadFailed($refused->getMessage(), $refused->getCode(), previous: $refused);
        } catch (ExceptionInterface $failure) {
            throw new ForgeReadFailed(sprintf('git %s did not run: %s', $arguments[0], $failure->getMessage()), $failure->getCode(), previous: $failure);
        }

        return $process;
    }
}
