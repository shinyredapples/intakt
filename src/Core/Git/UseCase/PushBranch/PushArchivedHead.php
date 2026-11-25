<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\ArchivedHeadPush;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitServices;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\ExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

// Pushes from the archive through the same git calls as a build push, but signs nothing, merges nothing and opens no pull request, because a restore puts back a head that was already on the forge.
final readonly class PushArchivedHead implements ArchivedHeadPush
{
    private const string BRANCHES = 'refs/heads/';

    public function __construct(
        private BranchGit $git,
        private LockFactory $locks,
        #[Autowire(service: GitServices::BRANCH_ARCHIVES)]
        private Mirrors $archives,
    ) {
    }

    #[Override]
    public function push(ArchivedHead $head, string $remote): void
    {
        $archive = $this->archiveOf($head);
        $this->refuseMalformedHead($head, $archive);
        $lock = $this->lockedBranch($head);

        try {
            $this->restore($head, $archive, $remote);
        } finally {
            $this->release($lock);
        }
    }

    private function archiveOf(ArchivedHead $head): string
    {
        try {
            return $this->archives->pathOf($head->project);
        } catch (ProjectNameRefused $refused) {
            throw BranchNotPushed::because($refused->getMessage(), $refused);
        }
    }

    // A name git allows and a commit the archive holds (BranchHead already holds an object id), because a tree or blob is no branch head.
    private function refuseMalformedHead(ArchivedHead $head, string $archive): void
    {
        $this->git->lines($archive, ['check-ref-format', self::BRANCHES.$head->head->branch]);

        if ('commit' !== $this->git->lines($archive, ['cat-file', '-t', $head->head->commit])) {
            throw BranchNotPushed::because(sprintf('the archive holds no commit %s', $head->head->commit));
        }
    }

    // The lock of a build push of the same branch, tried without waiting, because a restore that meets a push has nothing to add.
    private function lockedBranch(ArchivedHead $head): LockInterface
    {
        $lock = $this->locks->createLock(PushLockName::of($head->project, $head->head->branch), autoRelease: false);

        try {
            $acquired = $lock->acquire(false);
        } catch (ExceptionInterface $failure) {
            throw BranchNotPushed::because('the lock store failed: '.$failure->getMessage(), $failure);
        }

        return $acquired ? $lock : throw BranchNotPushed::because('another push of this branch is running');
    }

    private function restore(ArchivedHead $head, string $archive, string $remote): void
    {
        $branch = $head->head->branch;
        $this->git->refuseRewrittenRemote($archive, $remote);

        if (null !== $this->git->remoteTip($archive, $remote, $branch)) {
            throw BranchNotPushed::because(sprintf('the branch %s exists on the remote, and a restore never overwrites a branch', $branch));
        }

        // A lease on an empty value lets the push only create the branch: one made since the read above is refused by git and by the remote's ref update, also when the archived head would fast-forward it.
        $exitCode = $this->git->exitCodeAtRemote($archive, ['push', '--quiet', '--force-with-lease='.self::BRANCHES.$branch.':', '--', $remote, $head->head->commit.':'.self::BRANCHES.$branch]);

        if (0 !== $exitCode) {
            throw BranchNotPushed::because(sprintf('the remote refused the restore of %s (git push exited with %d)', $branch, $exitCode));
        }

        if ($this->git->remoteTip($archive, $remote, $branch) !== $head->head->commit) {
            throw BranchNotPushed::because(sprintf('the remote branch %s is not at the archived head after the push', $branch));
        }
    }

    // A release that fails is left to the lock's time to live, because the restore's own outcome, landed or failed, is what its caller needs.
    private function release(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (ExceptionInterface) {
            return;
        }
    }
}
