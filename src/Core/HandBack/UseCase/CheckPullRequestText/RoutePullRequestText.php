<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use NoDiscard;

// It only returns the route and never touches the bundle, the mirror or the branch, so a text sent back keeps the build's commits.
final readonly class RoutePullRequestText
{
    public function __construct(
        private PullRequestTextRoutes $routes,
    ) {
    }

    /**
     * @throws PullRequestTextRoundNotRecorded
     */
    #[NoDiscard]
    public function route(TaskBranch $task, string $taskText, string $body): PullRequestTextOutcome|FailedPullRequestText
    {
        $outcome = PullRequestTextRule::judge($taskText, $body);

        if (!$outcome->passed()) {
            return $this->routes->failed($task, $outcome);
        }

        $this->routes->passed($task, $outcome);

        return $outcome;
    }
}
