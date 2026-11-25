<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Psr\Log\LoggerInterface;

// A family holds one value, so the value applied earlier is taken off the pull request and the comment says which one counts (docs/design/05-architecture/04-labels.md).
// The comment also says how to take a removal back, because GitHub removes a label by name only, so a value applied again in the instant before the removal is removed too, and Intakt never applies a steering label to put it back.
final readonly class OutvotedLabels
{
    private const string REMOVED = 'Two values of one label family were set on this change. Only the one applied last counts, so Intakt removed: %s. If you applied one of them again just now, apply it once more: the value applied last counts.';

    public function __construct(
        private GitHubLabelWrites $writes,
        private GitHubIssueComments $comments,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $outvoted
     */
    public function removeFrom(string $project, PullRequestAddress $address, array $outvoted): void
    {
        $labels = array_values(array_unique($outvoted));
        if ([] === $labels) {
            return;
        }

        try {
            foreach ($labels as $label) {
                $this->writes->remove($address->issuePath(), $label);
            }
            $this->comments->post(new TaskIssue($project, $address->number), sprintf(self::REMOVED, implode(', ', array_map(static fn (string $label): string => '`'.$label.'`', $labels))));
            // Boundary: logged and dropped, because the round already steers by the value applied last, and the next round tries the removal again.
        } catch (ForgeWriteFailed|ForgeRateLimited $failure) {
            $this->logger->warning('The outvoted labels of pull request {number} of {project} were not removed: {reason}', ['number' => $address->number, 'project' => $project, 'reason' => $failure->getMessage()]);
        }
    }
}
