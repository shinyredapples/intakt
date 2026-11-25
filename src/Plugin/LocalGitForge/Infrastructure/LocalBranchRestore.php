<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\ArchivedHeadPush;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RemoteWrite;
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
        $before = $this->branchRefs($name, 'before the push');

        $this->settle($restore, $before, $this->failureOfPush($restore, $common));
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

    // The ref itself and not the commit it resolves to, because a push to a symbolic branch whose target is missing creates that target, and ls-remote in the push does not list such a branch. A ref that cannot be read is refused alike, because only a missing one may be created.
    private function refuseExisting(string $name): void
    {
        if (self::MISSING !== $this->git($name, ['show-ref', '--exists', 'refs/heads/'.$name])->getExitCode()) {
            throw $this->notRestored($name, 'it exists or cannot be read, and a restore never overwrites a branch.');
        }
    }

    // Every ref under refs/heads/ with its commit and, for a symbolic one, its target, so the refs that exist before the push are known by name and never removed after it.
    /**
     * @return array<string, array{string, string}>
     */
    private function branchRefs(string $name, string $moment): array
    {
        $process = $this->git($name, ['for-each-ref', '--format=%(refname) %(objectname) %(symref)', 'refs/heads/']);

        if (!$process->isSuccessful()) {
            throw $this->notRestored($name, sprintf('git for-each-ref failed %s: %s', $moment, trim($process->getErrorOutput())));
        }

        $refs = [];

        // No ref name holds a space, so the first two spaces of a line end the name and the commit.
        foreach (array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => '' !== $line) as $line) {
            $fields = explode(' ', $line, 3);
            $refs[$fields[0]] = [$fields[1] ?? '', $fields[2] ?? ''];
        }

        return $refs;
    }

    private function failureOfPush(BranchRestore $restore, string $common): ?BranchNotPushed
    {
        try {
            $this->archivedHeads->push(new ArchivedHead($restore->project, new BranchHead($restore->name, $restore->head->hash)), $common);
        } catch (BranchNotPushed $failed) {
            return $failed;
        }

        return null;
    }

    // A symbolic branch another writer makes after the check is not listed to the push, whose empty lease then lets the remote create the branch it names. That ref is removed only when git push reported the new branch and it is the one new ref, because only then is it proven to be the push's own; in every other case nothing is removed and the new refs are named.
    /**
     * @param array<string, array{string, string}> $before
     */
    private function settle(BranchRestore $restore, array $before, ?BranchNotPushed $failed): void
    {
        $name = $restore->name;

        // A push refused before git push ran wrote nothing, so no ref is looked for and none removed.
        if (RemoteWrite::None === $failed?->write) {
            throw $this->notRestored($name, $failed->getMessage(), $failed);
        }

        $made = $this->refsMadeSince($restore, $before);
        $ref = 'refs/heads/'.$name;
        $elsewhere = array_values(array_diff($made, [$ref]));

        if (in_array($ref, $made, true) && !$failed instanceof BranchNotPushed) {
            return;
        }

        if ([] === $elsewhere || in_array($ref, $made, true)) {
            throw $this->notRestored($name, $failed?->getMessage() ?? 'it is no branch at the archived head after the push, so another writer changed it during the restore.', $failed);
        }

        $target = $elsewhere[0];

        if (RemoteWrite::Unknown === $failed?->write || [$target] !== $elsewhere) {
            throw $this->notRestored($name, sprintf('another writer changed it during the restore, the push may have written one of %s, and nothing was removed, because none is proven to be the push\'s own.', implode(', ', $elsewhere)), $failed);
        }

        $undo = $this->git($name, ['update-ref', '--no-deref', '-d', $target, $restore->head->hash]);

        throw $this->notRestored($name, $undo->isSuccessful() ? sprintf('another writer made it a symbolic branch during the restore, so the push wrote %s, which was removed again.', $target) : sprintf('another writer made it a symbolic branch during the restore, so the push wrote %s, which could not be removed: %s', $target, trim($undo->getErrorOutput())), $failed);
    }

    // Only refs that are not symbolic, hold the archived head and were missing before the push, because the push's empty lease can create a ref and never move one, so a ref that was there before is not the push's and is never removed.
    /**
     * @param array<string, array{string, string}> $before
     *
     * @return list<string>
     */
    private function refsMadeSince(BranchRestore $restore, array $before): array
    {
        $head = $restore->head->hash;
        $after = $this->branchRefs($restore->name, 'after the push, so a branch the push wrote through a symbolic branch may be left');

        return array_keys(array_filter($after, static fn (array $state, string $ref): bool => [$head, ''] === $state && !array_key_exists($ref, $before), ARRAY_FILTER_USE_BOTH));
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
