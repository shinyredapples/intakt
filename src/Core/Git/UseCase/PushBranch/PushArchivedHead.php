<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\ArchivedHeadPush;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitServices;
use Intakt\Core\Git\Contract\RemoteCredential;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Override;
use SensitiveParameter;
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
    public function push(ArchivedHead $head, #[SensitiveParameter] string $remote, ?RemoteCredential $credential = null): void
    {
        $archive = $this->archiveOf($head);
        $this->refuseMalformedHead($head, $archive);
        $lock = $this->lockedBranch($head);

        try {
            $this->restore($head, $archive, $remote, $credential);
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

    // A name git allows and a commit the archive holds under exactly this id, because a tree or blob is no branch head, and a SHA-256 archive takes a 40-digit id as an abbreviation of a longer one, which the read after the push would never find.
    private function refuseMalformedHead(ArchivedHead $head, string $archive): void
    {
        $commit = $head->head->commit;
        $this->git->lines($archive, ['check-ref-format', self::BRANCHES.$head->head->branch]);

        if ('commit' !== $this->git->lines($archive, ['cat-file', '-t', $commit])) {
            throw BranchNotPushed::because(sprintf('the archive holds no commit %s', $commit));
        }

        if ($commit !== $this->git->lines($archive, ['rev-parse', '--verify', '--end-of-options', $commit.'^{commit}'])) {
            throw BranchNotPushed::because(sprintf('%s is no full commit id of the archive', $commit));
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

    // The remote lists no dangling symbolic branch, so a forge that can have one checks for it before this push.
    private function restore(ArchivedHead $head, string $archive, #[SensitiveParameter] string $remote, ?RemoteCredential $credential): void
    {
        $branch = $head->head->branch;
        $ref = self::BRANCHES.$branch;
        $this->git->refuseRewrittenRemote($archive, $remote);

        if (null !== $this->git->remoteTip($archive, $remote, $branch, $credential)) {
            throw BranchNotPushed::because(sprintf('the branch %s exists on the remote, and a restore never overwrites a branch', $branch));
        }

        // A lease on an empty value lets the push only create the branch: one made since the read above is refused by git and by the remote's ref update, also when the archived head would fast-forward it.
        [$exitCode, $statuses] = $this->git->outcomeAtRemote($archive, ['push', '--porcelain', '--force-with-lease='.$ref.':', '--', $remote, $head->head->commit.':'.$ref], $credential);

        if (0 !== $exitCode) {
            throw BranchNotPushed::because(sprintf('the remote refused the restore of %s (git push exited with %d)', $branch, $exitCode));
        }

        // Only a new branch counts, because a branch another writer made at the same head meanwhile is "up to date" to git, which then pushes nothing and exits with 0.
        if (!in_array("*\t".$head->head->commit.':'.$ref."\t[new branch]", explode("\n", $statuses), true)) {
            throw BranchNotPushed::because(sprintf('the push did not create the branch %s, so another writer made it during the restore', $branch));
        }

        if ($this->git->remoteTip($archive, $remote, $branch, $credential) !== $head->head->commit) {
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
