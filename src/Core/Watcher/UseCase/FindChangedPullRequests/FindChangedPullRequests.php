<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\FindChangedPullRequests;

use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use Psr\Log\LoggerInterface;

final readonly class FindChangedPullRequests
{
    public function __construct(
        private OpenChanges $forge,
        private SeenListings $seen,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<OpenChange>
     *
     * @throws ListingInProgress
     */
    public function __invoke(string $project): array
    {
        return $this->seen->whileListing($project, fn (): array => $this->listChanged($project));
    }

    /**
     * @return list<OpenChange>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
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
}
