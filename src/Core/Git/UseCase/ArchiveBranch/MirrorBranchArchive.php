<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ArchiveBranch;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Core\Git\Contract\UnsignedCommitRefused;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

final readonly class MirrorBranchArchive implements BranchArchive
{
    // Outside refs/heads and refs/tags, so no push of branches or tags ever carries an archive ref, and UpdateMirror leaves the namespace out of its pruning fetch.
    public const string NAMESPACE = 'refs/intakt/archive/';

    // Sortable as text, so the newest archive of a branch is the last ref name.
    private const string STAMP = 'Ymd\THis\Z';

    public function __construct(
        private UpdateMirror $mirror,
        private Mirrors $mirrors,
        private BranchGit $git,
        private SignedCommits $signedCommits,
        private ClockInterface $clock,
    ) {
    }

    public function refresh(string $project, string $remote): void
    {
        try {
            $this->mirror->update($project, $remote);
            // Boundary: every way the update fails means the same to cleanup, so it deletes nothing that is not archived.
        } catch (Throwable $failed) {
            throw BranchNotArchived::because(sprintf('the mirror of "%s" was not updated (%s)', $project, $failed->getMessage()), $failed);
        }
    }

    public function headOf(string $project, BranchHead $head, string $base): ?MirroredHead
    {
        try {
            $mirror = $this->mirrors->pathOf($project);
            $committedAt = $this->git->lines($mirror, ['log', '-1', '--format=%ct', $head->commit]);
        } catch (ProjectNameRefused|BranchNotPushed) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('!U', trim($committedAt), new DateTimeZone('UTC'));

        // A head whose time git does not give is unknown, so the rules keep it.
        return false === $moment ? null : new MirroredHead($moment, $this->isClaimed($mirror, $head->commit, $base));
    }

    public function archive(string $project, BranchHead $head): ArchivedBranch
    {
        $mirror = $this->mirrorOf($project);
        $ref = self::NAMESPACE.$this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::STAMP).'/'.$head->branch;

        try {
            // The empty old value creates the ref only, so an archive is never overwritten.
            $this->git->output($mirror, ['update-ref', '--no-deref', $ref, $head->commit, '']);
        } catch (BranchNotPushed $failed) {
            throw BranchNotArchived::because(sprintf('git did not write %s', $ref), $failed);
        }

        return new ArchivedBranch($head->branch, $head->commit, $ref, $mirror);
    }

    public function latestOf(string $project, string $branch): ?ArchivedBranch
    {
        $mirror = $this->mirrorOf($project);

        try {
            $listed = $this->git->lines($mirror, ['for-each-ref', '--format=%(objectname) %(refname)', '--sort=refname', self::NAMESPACE]);
        } catch (BranchNotPushed $failed) {
            throw BranchNotArchived::because('git did not list the archive refs', $failed);
        }

        $archives = array_values(array_filter(array_map(fn (string $line): ?ArchivedBranch => $this->archiveOf($line, $mirror), explode("\n", $listed)), static fn (?ArchivedBranch $archived): bool => $archived?->branch === $branch));

        return array_last($archives);
    }

    private function isClaimed(string $mirror, string $head, string $base): bool
    {
        try {
            $this->signedCommits->verify($mirror, $head, $base);

            return false;
        } catch (UnsignedCommitRefused|BranchNotPushed) {
            // A check that cannot answer claims the branch too, because keeping it costs nothing and a deleted person's commit is work lost to them.
            return true;
        }
    }

    private function mirrorOf(string $project): string
    {
        try {
            return $this->mirrors->pathOf($project);
        } catch (ProjectNameRefused $refused) {
            throw BranchNotArchived::because($refused->getMessage(), $refused);
        }
    }

    private function archiveOf(string $line, string $mirror): ?ArchivedBranch
    {
        [$commit, $ref] = [...explode(' ', $line, 2), ''];
        $stamped = explode('/', substr($ref, strlen(self::NAMESPACE)), 2);

        return 2 === count($stamped) ? new ArchivedBranch($stamped[1], $commit, $ref, $mirror) : null;
    }
}
