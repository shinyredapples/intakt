<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\FindChangedPullRequests;

use Closure;
use Intakt\Core\Watcher\Contract\ListingInProgress;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use Psr\Log\LoggerInterface;
use Throwable;

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

    // The work is given the listing to run and holds the listing's lock until it returns, so what the work decides from the listing and records, committed included, is not decided again by an overlapping listing.
    /**
     * @template T
     *
     * @param Closure(Closure(): list<OpenChange>): T $work
     *
     * @return T
     *
     * @throws ListingInProgress
     * @throws Throwable
     */
    public function whileListing(string $project, Closure $work): mixed
    {
        return $this->seen->whileListing($project, fn (): mixed => $work(fn (): array => $this->listChanged($project)));
    }

    // As the last listing that completed saw them, so an unchanged listing still names every open pull request.
    /**
     * @return list<OpenChange>
     */
    public function openOf(string $project): array
    {
        return $this->seen->openOf($project);
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
