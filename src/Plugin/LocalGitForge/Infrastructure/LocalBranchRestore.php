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

// Apart from LocalForgeBranches, because a restore shares none of the checks its reads and deletes make, and itself never deletes.
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
        $before = $this->refs($name, 'before the push');

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

    // Every ref of every namespace with its object and, for a symbolic one, its target, because a push through a branch another writer made symbolic can write a ref anywhere, a tag included, and the refusal has to name it.
    /**
     * @return array<string, array{string, string}>
     */
    private function refs(string $name, string $moment): array
    {
        $process = $this->git($name, ['for-each-ref', '--format=%(refname) %(objectname) %(symref)', 'refs/']);

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

    // A restore only when git push reported the new branch (no failure) and the branch is then a plain ref at the archived head that was missing before; everything else is refused. Nothing is ever deleted or moved, because no listing proves which writer made a ref, and a left-over ref can be removed by hand while a deleted branch of someone else is lost.
    /**
     * @param array<string, array{string, string}> $before
     */
    private function settle(BranchRestore $restore, array $before, ?BranchNotPushed $failed): void
    {
        $name = $restore->name;

        // A push refused before git push ran wrote nothing, so there is no ref to name.
        if (RemoteWrite::None === $failed?->write) {
            throw $this->notRestored($name, $failed->getMessage(), $failed);
        }

        $head = $restore->head->hash;
        $ref = 'refs/heads/'.$name;
        $after = $this->refs($name, 'after the push, so a ref the push wrote may be left');

        if (!$failed instanceof BranchNotPushed && !array_key_exists($ref, $before) && [$head, ''] === ($after[$ref] ?? null)) {
            return;
        }

        $reason = $failed?->getMessage() ?? sprintf('%s is no plain branch at the archived head after the push, so another writer changed it during the restore', $ref);
        $left = array_keys(array_filter($after, static fn (array $state, string $made): bool => $head === $state[0] && !array_key_exists($made, $before), ARRAY_FILTER_USE_BOTH));

        throw $this->notRestored($name, sprintf('%s. %s', $reason, [] === $left ? 'Nothing was removed, and no ref new since the push began holds the archived head.' : sprintf('Nothing was removed; the refs new since the push began that hold the archived head are %s.', implode(', ', $left))), $failed);
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
