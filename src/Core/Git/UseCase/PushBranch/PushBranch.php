<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Closure;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\MainConflicts;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Core\Git\Contract\RefusedPush;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use NoDiscard;
use Override;
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

final readonly class PushBranch implements BranchPush
{
    private const string BRANCHES = 'refs/heads/';

    public function __construct(
        private BranchGit $git,
        private SignedCommits $signed,
        private PushRecords $records,
        private ChangeOpening $changes,
        private LockFactory $locks,
    ) {
    }

    // Repeated from the interface, because PHP reports a discarded result only for the method that runs.
    #[Override]
    #[NoDiscard('a merge-main outcome has to go back to the builder, and a refused push to its caller')]
    public function push(BranchPushRequest $request): PushedBranch|MainConflicts|RefusedPush
    {
        return $this->locked($request, $this->pushed(...));
    }

    // The same lock as the push, so no push of the branch runs between the read of the remote branch and the pull request write.
    #[Override]
    public function openChange(BranchPushRequest $request): ChangeId
    {
        return $this->locked($request, $this->changeOfPushed(...));
    }

    private function pushed(BranchPushRequest $request): PushedBranch|MainConflicts|RefusedPush
    {
        $this->refuseRewrittenRemote($request);
        $main = $this->mainOf($request->repository);
        $head = $this->signed->of($request->repository, $request->accepted, $main);
        $this->signed->verify($request->repository, $head, $main);

        return $this->notLanded($request, $head, $main) ?? new PushedBranch($head);
    }

    /**
     * @template T
     *
     * @param Closure(BranchPushRequest): T $work
     *
     * @return T
     */
    private function locked(BranchPushRequest $request, Closure $work): mixed
    {
        $lock = $this->lockedBranch($request);

        try {
            $outcome = $work($request);
            // Boundary: releases the branch lock on any failure and rethrows it, because a lock left held would refuse the branch's later pushes until it expires.
        } catch (Throwable $failure) {
            $this->releaseAfter($lock);

            throw $failure;
        }

        $this->release($lock);

        return $outcome;
    }

    // A store failure becomes the contract's own failure, because a caller of BranchPush must not know which lock store the Git module uses.
    private function lockedBranch(BranchPushRequest $request): LockInterface
    {
        // Released here only, never on destruct: Symfony's destructor would release a lock whose release failed once more and throw past BranchNotPushed.
        $lock = $this->locks->createLock('push:'.hash('sha256', json_encode([$request->project, $request->branch], JSON_THROW_ON_ERROR)), autoRelease: false);

        try {
            // Without waiting, because a push that meets another push of the same branch has nothing to add; its caller tries again later.
            $acquired = $lock->acquire(false);
        } catch (ExceptionInterface $failure) {
            throw BranchNotPushed::because('the lock store failed: '.$failure->getMessage(), $failure);
        }

        return $acquired ? $lock : throw BranchNotPushed::because('another push of this branch is running');
    }

    // Signed again rather than taken from the caller, because the signature is deterministic and only a head this use case pushed may get a pull request.
    private function changeOfPushed(BranchPushRequest $request): ChangeId
    {
        $this->refuseRewrittenRemote($request);
        $main = $this->mainOf($request->repository);
        $head = $this->signed->of($request->repository, $request->accepted, $main);

        if (!$this->records->hasPushed($request, $head)) {
            throw BranchNotPushed::because(sprintf('this head of %s is not pushed yet, so no pull request is opened', $request->branch));
        }

        $this->refuseAMovedBranch($request, $head, $this->remoteTip($request));

        return $this->openedChange($request, $head, $main);
    }

    // The failure of the push is what its caller needs, and a lock left held expires with its time to live.
    private function releaseAfter(LockInterface $lock): void
    {
        try {
            $this->release($lock);
        } catch (BranchNotPushed) {
            return;
        }
    }

    // Reported as not pushed even after a push, because the next call finds the recorded push at the remote tip and reports the branch pushed without pushing again.
    private function release(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (ExceptionInterface) {
            // Once more, so a store back after a short outage frees the branch now instead of at the lock's expiry.
            $this->releaseOnce($lock);
        }
    }

    private function releaseOnce(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (ExceptionInterface $failure) {
            throw BranchNotPushed::because('the lock store failed: '.$failure->getMessage(), $failure);
        }
    }

    // Checked by the push itself and not once when the task starts, because git reads the repository's config at the push, and a rewrite can have been written since. An empty prefix matches every remote, as git applies it to every URL. The remote is not named, because it can hold a credential.
    private function refuseRewrittenRemote(BranchPushRequest $request): void
    {
        $section = 'remote.'.$request->remote.'.';

        foreach ($this->git->addressEntries($request->repository) as [$key, $value]) {
            // A remote section named exactly like the remote string, because git push takes the string as that remote's name and pushes to its url or pushurl. Git keeps the section's name as written and the key after it has no dot, so a longer name that only starts with the remote is another section.
            if (str_starts_with($key, $section) && !str_contains(substr($key, strlen($section)), '.')) {
                throw BranchNotPushed::because('the repository\'s config has a remote section named like the remote, so git would push to that section\'s address instead of the one the forge checks');
            }

            if (str_starts_with($key, 'url.') && str_starts_with($request->remote, $value)) {
                throw BranchNotPushed::because('the repository\'s config rewrites the remote\'s address (url.<base>.insteadOf or pushInsteadOf), so git would read and push another repository than the one the forge checks');
            }
        }
    }

    private function mainOf(string $repository): string
    {
        $main = $this->git->lines($repository, ['symbolic-ref', '--quiet', 'HEAD']);

        return str_starts_with($main, self::BRANCHES) ? $main : throw BranchNotPushed::because('the repository\'s HEAD names no branch, so it has no main');
    }

    // Null only when the remote branch is the signed head, because the caller writes the pull request for nothing else.
    private function notLanded(BranchPushRequest $request, string $head, string $main): MainConflicts|RefusedPush|null
    {
        // Read on every call, also for a recorded push, because the ledger knows nothing of a later head or of another writer that moved the branch since.
        $remoteTip = $this->remoteTip($request);

        if ($this->records->hasPushed($request, $head)) {
            $this->refuseAMovedBranch($request, $head, $remoteTip);

            return null;
        }

        // Not when the remote already holds this signed head: a crash after the push left only its record missing, and a main that conflicts since cannot take the push back.
        $conflicts = $remoteTip === $head ? null : $this->conflictsWith($request, $main);

        if ($conflicts instanceof MainConflicts) {
            return $conflicts;
        }

        // Before push_intended and git push, because a push moves the head of every open pull request of the branch, also of one another account opened.
        $this->changes->refuseForeignChange($this->proposedChange($request, $main));

        return $this->pushOnce($request, $head, $remoteTip);
    }

    private function remoteTip(BranchPushRequest $request): ?string
    {
        $ref = self::BRANCHES.$request->branch;
        $listed = $this->git->outputFromRemote($request->repository, ['ls-remote', '--', $request->remote, $ref]);

        // Git matches the pattern against the tail of every ref, so "refs/heads/a/refs/heads/task" is listed too and may come first: only the exact ref is the branch.
        foreach (explode("\n", $listed) as $line) {
            $fields = explode("\t", $line, 2);

            if ($ref === ($fields[1] ?? null)) {
                return $fields[0];
            }
        }

        return null;
    }

    // Refused before the pull request is written, because a late retry of an earlier head must not overwrite the text of the head that replaced it.
    private function refuseAMovedBranch(BranchPushRequest $request, string $head, ?string $remoteTip): void
    {
        if ($remoteTip !== $head) {
            throw BranchNotPushed::because(sprintf('the remote branch %s moved since this head was pushed', $request->branch));
        }
    }

    // The merge is tried in the repository's object store only, so a conflict leaves nothing on the remote and no ref anywhere.
    private function conflictsWith(BranchPushRequest $request, string $main): ?MainConflicts
    {
        $paths = $this->git->mergeTree($request->repository, $main, $request->accepted->head);

        return null === $paths ? null : new MainConflicts(new Paths(...$paths));
    }

    private function openedChange(BranchPushRequest $request, string $head, string $main): ChangeId
    {
        $recorded = $this->records->openedChange($request, $head);

        if (null !== $recorded) {
            return new ChangeId($recorded);
        }

        // Recorded before the forge is written to, because a write whose own record is then refused must not leave an earlier text's record as the last word.
        $this->records->intendChange($request, $head);

        // The forge looks for an open pull request of the branch first, so a crash after the pull request was opened and before it was recorded opens no second one, and a corrected text of the same commits updates the open one.
        $opened = $this->changes->openOrUpdate($this->proposedChange($request, $main));
        $this->records->open($request, $head, $opened);

        return $opened->id;
    }

    private function proposedChange(BranchPushRequest $request, string $main): ProposedChange
    {
        return new ProposedChange($request->project, $request->branch, substr($main, strlen(self::BRANCHES)), $request->text);
    }

    private function pushOnce(BranchPushRequest $request, string $head, ?string $remoteTip): ?RefusedPush
    {
        $alreadyThere = $remoteTip === $head;

        // A plain push, never a forced one: a branch that moved since the checked base is refused here, and git refuses a push that is not a fast-forward.
        if (!$alreadyThere && $remoteTip !== $request->accepted->base) {
            throw BranchNotPushed::because(sprintf('the remote branch %s moved since the hand-back was checked', $request->branch));
        }

        if (!$this->records->isIntended($request, $head)) {
            $this->records->intend($request, $head);
        }

        $exitCode = $alreadyThere ? 0 : $this->git->exitCodeAtRemote($request->repository, ['push', '--quiet', '--', $request->remote, $head.':'.self::BRANCHES.$request->branch]);

        if (0 !== $exitCode) {
            return $this->refused($request, $head, $exitCode);
        }

        $this->records->push($request, $head);

        return null;
    }

    // Recorded, because the caller of a later part reports the refusal from the ledger; the next call reads the remote branch first and tries the push again.
    private function refused(BranchPushRequest $request, string $head, int $exitCode): RefusedPush
    {
        $refused = new RefusedPush(sprintf('the remote refused the push of %s (git push exited with %d)', $request->branch, $exitCode));
        $this->records->refuse($request, $head, $refused->reason);

        return $refused;
    }
}
