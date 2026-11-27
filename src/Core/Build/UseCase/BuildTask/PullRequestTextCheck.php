<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\HandBack\Contract\FailedPullRequestText;
use Intakt\Core\HandBack\Contract\PullRequestTextRound;
use Intakt\Core\HandBack\Contract\PullRequestTextRoundNotRecorded;
use Intakt\Core\HandBack\Contract\PullRequestTextRoute;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\HandBack\Contract\PullRequestTextTaskRefused;

// Counted on the project's mirror and the task's branch, as a person's run of the round names them, so both count toward one task.
final readonly class PullRequestTextCheck
{
    public function __construct(
        private BuildCheckout $checkout,
        private PullRequestTextRound $round,
    ) {
    }

    // The round's fixed reason and the missing item numbers, never the text, because what the build wrote is untrusted; a miss handed to the reviewers is no failure, because the head goes on to the push and to review.
    public function failureOf(BuildTaskRequest $request, string $taskText, string $body): ?string
    {
        try {
            $result = $this->round->route(PullRequestTextTask::resolved($this->checkout->mirrorOf($request->project), $request->branch), $taskText, $body);
        } catch (BuildCheckoutFailed|PullRequestTextTaskRefused|PullRequestTextRoundNotRecorded $failure) {
            return $failure->getMessage();
        }

        if (!$result instanceof FailedPullRequestText || PullRequestTextRoute::ToReviewers === $result->route) {
            return null;
        }

        $missing = $result->outcome->missingItems;

        return 'the pull request text is sent back to the builder: '.$result->outcome->verdict->reason().($missing->isEmpty() ? '' : '; missing: '.$missing->references());
    }
}
