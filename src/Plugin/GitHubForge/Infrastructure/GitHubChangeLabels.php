<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Review\Contract\Labels;
use Intakt\Plugin\Forge\Contract\ChangeLabels;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\TrustedLabels;
use Psr\Log\LoggerInterface;

final readonly class GitHubChangeLabels implements ChangeLabels
{
    public function __construct(
        private TrustedPullRequestLabels $trusted,
        private OutvotedLabels $outvoted,
        private LoggerInterface $logger,
    ) {
    }

    public function steeringOf(string $project, OpenChange $open): Labels
    {
        $trusted = $this->trustedOf($project, $open);
        if ([] !== $trusted->outvoted) {
            $this->removeStillOutvoted($project, $open, $trusted->outvoted);
        }

        return $trusted->steering;
    }

    private function trustedOf(string $project, OpenChange $open): TrustedLabels
    {
        try {
            return $this->trusted->of($project, PullRequestAddress::of($open->id), $open->updatedAt);
            // Boundary: a failed read counts no label, because a read that failed is never a decision; the round runs on the configured panel.
        } catch (ChangeNotFound|ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            $this->logger->warning('The labels of {change} of {project} could not be read, so none steers: {reason}', ['change' => $open->id->value, 'project' => $project, 'reason' => $failure->getMessage()]);

            return new TrustedLabels(new Labels(), []);
        }
    }

    // Read again just before the removal, because GitHub removes a label by name only and the read fails once the pull request changed after its listing, so a value reapplied since the first read stays.
    /**
     * @param list<string> $outvoted
     */
    private function removeStillOutvoted(string $project, OpenChange $open, array $outvoted): void
    {
        try {
            $address = PullRequestAddress::of($open->id);
            $again = $this->trusted->of($project, $address, $open->updatedAt);
            // Boundary: logged and dropped, because the round already steers by the first read, and the next round tries the removal again.
        } catch (ChangeNotFound|ForgeReadFailed|ForgeRateLimited|TrustNotChecked $failure) {
            $this->logger->warning('The outvoted labels of {change} of {project} were kept, because its labels could not be read again: {reason}', ['change' => $open->id->value, 'project' => $project, 'reason' => $failure->getMessage()]);

            return;
        }

        $this->outvoted->removeFrom($project, $address, array_values(array_intersect($outvoted, $again->outvoted)));
    }
}
