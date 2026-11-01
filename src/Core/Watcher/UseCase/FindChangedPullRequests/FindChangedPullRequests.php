<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\FindChangedPullRequests;

use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final readonly class FindChangedPullRequests
{
    public function __construct(
        private OpenChanges $forge,
        private SeenListings $seen,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<OpenChange>
     *
     * @throws ForgeReadFailed
     * @throws ListingInProgress
     */
    public function __invoke(string $project): array
    {
        $lock = $this->lockOf($project);

        try {
            return $this->listChanged($project);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<OpenChange>
     */
    private function listChanged(string $project): array
    {
        $listing = $this->forge->listOpen($project, $this->seen->validatorOf($project));

        if ($listing->isUnchanged()) {
            $this->logger->info('Listed the open pull requests: unchanged.', ['project' => $project, 'status' => 304, 'changed' => 0]);

            return [];
        }

        $changed = $this->seen->changedAmong($project, $listing->changes());
        // Recorded only after the list is complete, so a failure before this leaves the old validator and the next listing is a full one.
        $this->seen->record($project, $listing->changes(), $listing->validator);
        $this->logger->info('Listed the open pull requests.', ['project' => $project, 'status' => 200, 'open' => count($listing->changes()), 'changed' => count($changed)]);

        return $changed;
    }

    // Held across the listing because two listings of one project would both return the same pull request as changed.
    private function lockOf(string $project): LockInterface
    {
        $lock = $this->locks->createLock('watcher-listing-'.hash('sha256', $project));

        if (!$lock->acquire(false)) {
            throw ListingInProgress::for($project);
        }

        return $lock;
    }
}
