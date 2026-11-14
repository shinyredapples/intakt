<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\BranchPush;
use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\MainConflicts;
use Intakt\Core\Git\Contract\PushedBranch;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ProposedChange;
use NoDiscard;
use Override;
use Symfony\Component\Lock\LockFactory;

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
    #[NoDiscard('a merge-main outcome has to go back to the builder')]
    public function push(BranchPushRequest $request): PushedBranch|MainConflicts
    {
        // Without waiting, because a push that meets another push of the same branch has nothing to add; its caller tries again later.
        $lock = $this->locks->createLock('push:'.hash('sha256', json_encode([$request->project, $request->branch], JSON_THROW_ON_ERROR)));

        if (!$lock->acquire(false)) {
            throw BranchNotPushed::because('another push of this branch is running');
        }

        try {
            return $this->pushed($request);
        } finally {
            $lock->release();
        }
    }

    private function pushed(BranchPushRequest $request): PushedBranch|MainConflicts
    {
        $main = $this->mainOf($request->repository);
        $head = $this->signed->of($request->repository, $request->accepted, $main);
        $this->signed->verify($request->repository, $head, $main);

        if (!$this->records->hasPushed($request, $head)) {
            $remoteTip = $this->remoteTip($request);

            // Not when the remote already holds this signed head: a crash after the push left only its record missing, and a main that conflicts since cannot take the push back.
            $conflicts = $remoteTip === $head ? null : $this->conflictsWith($request, $main);

            if ($conflicts instanceof MainConflicts) {
                return $conflicts;
            }

            $this->pushOnce($request, $head, $remoteTip);
        }

        return new PushedBranch($head, $this->openedChange($request, $head, $main));
    }

    private function mainOf(string $repository): string
    {
        $main = $this->git->lines($repository, ['symbolic-ref', '--quiet', 'HEAD']);

        return str_starts_with($main, self::BRANCHES) ? $main : throw BranchNotPushed::because('the repository\'s HEAD names no branch, so it has no main');
    }

    // The merge is tried in the repository's object store only, so a conflict leaves nothing on the remote and no ref anywhere.
    private function conflictsWith(BranchPushRequest $request, string $main): ?MainConflicts
    {
        $paths = $this->git->mergeTree($request->repository, $main, $request->accepted->head);

        return null === $paths ? null : new MainConflicts($paths);
    }

    private function pushOnce(BranchPushRequest $request, string $head, ?string $remoteTip): void
    {
        $alreadyThere = $remoteTip === $head;

        // A plain push, never a forced one: a branch that moved since the checked base is refused here, and git refuses a push that is not a fast-forward.
        if (!$alreadyThere && $remoteTip !== $request->accepted->base) {
            throw BranchNotPushed::because(sprintf('the remote branch %s moved since the hand-back was checked', $request->branch));
        }

        if (!$this->records->isIntended($request, $head)) {
            $this->records->intend($request, $head);
        }

        if (!$alreadyThere) {
            $this->git->outputFromRemote($request->repository, ['push', '--quiet', '--', $request->remote, $head.':'.self::BRANCHES.$request->branch]);
        }

        $this->records->push($request, $head);
    }

    private function remoteTip(BranchPushRequest $request): ?string
    {
        $listed = trim($this->git->outputFromRemote($request->repository, ['ls-remote', '--', $request->remote, self::BRANCHES.$request->branch]));

        return '' === $listed ? null : explode("\t", $listed)[0];
    }

    private function openedChange(BranchPushRequest $request, string $head, string $main): ChangeId
    {
        $recorded = $this->records->openedChange($request, $head);

        if (null !== $recorded) {
            return new ChangeId($recorded);
        }

        // The forge looks for an open pull request of the branch first, so a crash after the pull request was opened and before it was recorded opens no second one, and a corrected text of the same commits updates the open one.
        $change = $this->changes->openOrUpdate(new ProposedChange($request->project, $request->branch, substr($main, strlen(self::BRANCHES)), $request->text));
        $this->records->open($request, $head, $change->value);

        return $change;
    }
}
