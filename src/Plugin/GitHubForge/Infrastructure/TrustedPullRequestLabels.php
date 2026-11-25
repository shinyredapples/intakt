<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\LabelFamilies;
use Intakt\Plugin\GitHubForge\Domain\TrustedLabels;

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

    // A pull request of another repository is refused, because the history and the trust are read for the configured one, so a removal sent to the pull request's own address would act on a repository nothing was checked for.
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     * @throws TrustNotChecked
     */
    public function of(string $project, PullRequestAddress $pull, DateTimeImmutable $listedAt): TrustedLabels
    {
        $address = $this->repositories->addressOf($project);
        if (!$pull->isIn($address)) {
            throw new ChangeNotFound(sprintf('Pull request %s/%s#%d is not in the repository of project %s.', $pull->owner, $pull->repository, $pull->number, $project));
        }

        $history = $this->histories->of($project, $pull->number, $listedAt);
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

        return new TrustedLabels(
            new Labels(...array_map(static fn (LabelApplication $application): string => $application->label, $this->families->winners($steering))),
            $this->families->losers($steering),
        );
    }
}
