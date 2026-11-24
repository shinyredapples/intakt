<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Domain\CheckedMain;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Intakt\Core\Watchdog\Infrastructure\LedgerOwnerPushNotices;
use Intakt\Core\Watchdog\Infrastructure\NoticeNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeNotARange;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;

final readonly class GuardMain
{
    // One lock for every project's halts and notices, so two guards that read main at once, from one clone or two, raise one card per violation and give one notice per owner push.
    private const string LOCK = 'watchdog-halts';

    public function __construct(
        private CheckMain $check,
        private LedgerHalts $halts,
        private LedgerOwnerPushNotices $notices,
        private LockFactory $locks,
    ) {
    }

    /**
     * @throws AHaltNamesItsProject
     * @throws CommitNameNotFull
     * @throws RecordedMergeNotARange
     * @throws OwnerKeyRefused
     * @throws MainNotRead
     * @throws ProcessFailedException
     * @throws HaltNotRecorded
     * @throws NoticeNotRecorded
     */
    public function guarded(GuardMainInput $input): GuardedMain
    {
        $this->mustNameAProject($input->project);
        $checked = $this->check->checked($input->repository, $input->watchedMain(), $input->trustedKeys());
        // A peer merge alone takes no lock, because it writes nothing: it is shown, never noticed or halted on.
        if ($checked->classification->isClean() && [] === $checked->classification->ownerPushes) {
            return new GuardedMain($this->halts->standingOf($input->project), [], $checked->classification->peerMerges);
        }

        return $this->recordedFor($input->project, $checked);
    }

    // The name goes into every halt and notice the ledger stores as JSON, so a name JSON cannot encode is refused before anything is read or recorded.
    private function mustNameAProject(string $project): void
    {
        if ('' === trim($project)) {
            throw new AHaltNamesItsProject('A halt names its project, and the project given is empty.');
        }
        if (!mb_check_encoding($project, 'UTF-8')) {
            throw new AHaltNamesItsProject('A halt names its project, and the project name given is not valid UTF-8.');
        }
    }

    // Every ledger read runs before the first notice is appended, because a read that fails after an append would lose a notice the ledger already took.
    private function recordedFor(string $project, CheckedMain $checked): GuardedMain
    {
        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);
        try {
            $this->raiseCardsFor($project, $checked);
            $standing = $this->halts->standingOf($project);
            $unnoticed = array_values(array_diff($checked->classification->ownerPushes, $this->notices->noticedOf($project)));

            return new GuardedMain($standing, $this->noticesGivenFor($project, $unnoticed), $checked->classification->peerMerges);
        } finally {
            $lock->release();
        }
    }

    private function raiseCardsFor(string $project, CheckedMain $checked): void
    {
        foreach ($checked->classification->violations as $violation) {
            if (!$this->halts->wasRaised($project, $violation, $checked->head)) {
                $this->halts->raise($project, $violation, $checked->head);
            }
        }
    }

    /**
     * @param list<string> $unnoticed
     *
     * @return list<string>
     */
    private function noticesGivenFor(string $project, array $unnoticed): array
    {
        $given = [];
        foreach ($unnoticed as $commit) {
            try {
                $this->notices->notice($project, $commit);
            } catch (NoticeNotRecorded $refused) {
                throw $refused->afterGiving($given);
            }
            $given[] = $commit;
        }

        return $given;
    }
}
