<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Intakt\Core\HandBack\Contract\FailedPullRequestText;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextRound;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use NoDiscard;
use Override;

// It only returns the route and never touches the bundle, the mirror or the branch, so a text sent back keeps the build's commits.
final readonly class RoutePullRequestText implements PullRequestTextRound
{
    public function __construct(
        private PullRequestTextRoutes $routes,
    ) {
    }

    #[Override]
    #[NoDiscard]
    public function route(PullRequestTextTask $task, string $taskText, string $body): PullRequestTextOutcome|FailedPullRequestText
    {
        $outcome = PullRequestTextRule::judge($taskText, $body);

        if (!$outcome->passed()) {
            return $this->routes->failed($task, $outcome);
        }

        $this->routes->passed($task, $outcome);

        return $outcome;
    }
}
