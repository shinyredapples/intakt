<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Infrastructure\HaltNotRecorded;
use Intakt\Core\Watchdog\Infrastructure\LedgerHalts;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\RecordedMergeNotARange;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ProcessFailedException;

final readonly class GuardMain
{
    // One lock for every project's halts, so two guards that read main at once raise one card per violation.
    private const string LOCK = 'watchdog-halts';

    public function __construct(
        private CheckMain $check,
        private LedgerHalts $halts,
        private LockFactory $locks,
    ) {
    }

    /**
     * @return list<OwnerCard>
     *
     * @throws AHaltNamesItsProject
     * @throws CommitNameNotFull
     * @throws RecordedMergeNotARange
     * @throws OwnerKeyRefused
     * @throws MainNotRead
     * @throws ProcessFailedException
     * @throws HaltNotRecorded
     */
    public function standingCardsAfterChecking(GuardMainInput $input): array
    {
        $this->mustNameAProject($input->project);
        $classification = $this->check->classified($input->repository, $input->watchedMain(), $input->ownerKey);
        $this->raiseCardsFor($input->project, $classification);

        return $this->halts->standingOf($input->project);
    }

    private function mustNameAProject(string $project): void
    {
        if ('' === trim($project)) {
            throw new AHaltNamesItsProject('A halt names its project, and the project given is empty.');
        }
    }

    private function raiseCardsFor(string $project, Classification $classification): void
    {
        if ($classification->isClean()) {
            return;
        }

        $lock = $this->locks->createLock(self::LOCK);
        $lock->acquire(true);
        try {
            foreach ($classification->violations as $violation) {
                if (!$this->halts->wasRaised($project, $violation)) {
                    $this->halts->raise($project, $violation);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
