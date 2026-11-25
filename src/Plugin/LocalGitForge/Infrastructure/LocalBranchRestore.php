<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\ArchivedHeadPush;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use Throwable;

// Apart from LocalForgeBranches, because a restore shares none of the checks its reads and deletes make.
final readonly class LocalBranchRestore
{
    private const int TIMEOUT_SECONDS = 60;
    private const int MISSING = 2;

    public function __construct(
        private LocalRepository $repository,
        private ArchivedHeadPush $archivedHeads,
        private HardenedGit $hardened,
    ) {
    }

    // The repository's absolute common directory as the remote, because git resolves a relative path from the archive, where it could name another repository; the push brings the commit's objects with it.
    public function restore(BranchRestore $restore): void
    {
        $name = $restore->name;
        $common = $this->commonDirectory($name);
        $this->refuseExisting($name);

        try {
            $this->archivedHeads->push(new ArchivedHead($restore->project, new BranchHead($name, $restore->head->hash)), $common);
        } catch (BranchNotPushed $failed) {
            $this->undoWriteThroughSymbolicBranch($name, $restore->head->hash);

            throw $this->notRestored($name, $failed->getMessage(), $failed);
        }

        $this->undoWriteThroughSymbolicBranch($name, $restore->head->hash);
    }

    // Only the newline git ends its answer with, because a directory's own name may end in one, and a name without it can be another repository.
    private function commonDirectory(string $name): string
    {
        $process = $this->git($name, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $output = $process->getOutput();

        if (!$process->isSuccessful() || !str_ends_with($output, "\n")) {
            throw $this->notRestored($name, sprintf('git rev-parse failed: %s', trim($process->getErrorOutput())));
        }

        return substr($output, 0, -1);
    }

    // A symbolic branch another writer makes after the check is not listed to the push, whose empty lease then lets the remote create the branch it names. That target did not exist when the push wrote it, so it is removed again while it still holds the archived head.
    private function undoWriteThroughSymbolicBranch(string $name, string $head): void
    {
        $symbolic = $this->git($name, ['symbolic-ref', '--quiet', 'refs/heads/'.$name]);

        if (1 === $symbolic->getExitCode()) {
            return;
        }

        if (!$symbolic->isSuccessful()) {
            throw $this->notRestored($name, sprintf('git symbolic-ref failed after the push: %s', trim($symbolic->getErrorOutput())));
        }

        $target = trim($symbolic->getOutput());
        $undo = $this->git($name, ['update-ref', '--no-deref', '-d', $target, $head]);

        throw $this->notRestored($name, $undo->isSuccessful()
            ? sprintf('another writer made it a symbolic branch during the restore, so the push wrote %s, which was removed again.', $target)
            : sprintf('another writer made it a symbolic branch during the restore, so the push may have written %s, which could not be removed: %s', $target, trim($undo->getErrorOutput())));
    }

    // The ref itself and not the commit it resolves to, because a push to a symbolic branch whose target is missing creates that target, and ls-remote in the push does not list such a branch. A ref that cannot be read is refused alike, because only a missing one may be created.
    private function refuseExisting(string $name): void
    {
        if (self::MISSING !== $this->git($name, ['show-ref', '--exists', 'refs/heads/'.$name])->getExitCode()) {
            throw $this->notRestored($name, 'it exists or cannot be read, and a restore never overwrites a branch.');
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function git(string $name, array $arguments): Process
    {
        // An argument with a NUL byte cannot reach git, because PHP refuses to start the process with it.
        if (array_any($arguments, static fn (string $argument): bool => str_contains($argument, "\0"))) {
            throw $this->notRestored($name, sprintf('git %s was given an argument with a NUL byte.', $arguments[0]));
        }

        try {
            $call = $this->hardened->call($this->repository->path, ...$arguments);
            $process = new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused|ExceptionInterface $failure) {
            throw $this->notRestored($name, sprintf('git %s did not run: %s', $arguments[0], $failure->getMessage()), $failure);
        }

        return $process;
    }

    private function notRestored(string $name, string $reason, ?Throwable $previous = null): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('The branch "%s" was not restored: %s', $name, $reason), (int) $previous?->getCode(), $previous);
    }
}
