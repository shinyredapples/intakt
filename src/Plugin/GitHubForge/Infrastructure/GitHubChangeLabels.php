<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Review\Contract\Labels;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Psr\Log\LoggerInterface;

final readonly class GitHubChangeLabels implements ChangeLabels
{
    public function __construct(
        private TrustedPullRequestLabels $trusted,
        private LoggerInterface $logger,
    ) {
    }

    public function steeringOf(string $project, OpenChange $open): Labels
    {
        try {
            return $this->trusted->of($project, PullRequestAddress::of($open->id)->number, $open->updatedAt);
            // Boundary: a failed read counts no label, because a read that failed is never a decision; the round runs on the configured panel.
        } catch (ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            $this->logger->warning('The labels of {change} of {project} could not be read, so none steers: {reason}', ['change' => $open->id->value, 'project' => $project, 'reason' => $failure->getMessage()]);

            return new Labels();
        }
    }
}
