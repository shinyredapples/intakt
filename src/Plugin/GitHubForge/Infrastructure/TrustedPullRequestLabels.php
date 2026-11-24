<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\LabelFamilies;

// A steering label counts only when an account with write access applied it and it is still on the pull request; a trust check that fails fails the read, so an unchecked label never steers.
final readonly class TrustedPullRequestLabels
{
    public function __construct(
        private IssueHistories $histories,
        private SteeringTrustCheck $trust,
        private WatchedRepositories $repositories,
        private LabelFamilies $families = new LabelFamilies(),
    ) {
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     * @throws TrustNotChecked
     */
    public function of(string $project, int $number, DateTimeImmutable $listedAt): Labels
    {
        $history = $this->histories->of($project, $number, $listedAt);
        $address = $this->repositories->addressOf($project);
        $repository = $address->owner().'/'.$address->name();

        $steering = [];
        $verdicts = [];
        foreach ($history->labels as $application) {
            if (!$history->currentLabels->contains($application->label)) {
                continue;
            }
            $verdicts[$application->appliedBy] ??= $this->trust->check($repository, $application->appliedBy)->steers();
            if ($verdicts[$application->appliedBy]) {
                $steering[] = $application;
            }
        }

        return new Labels(...array_map(static fn (LabelApplication $application): string => $application->label, $this->families->winners($steering)));
    }
}
