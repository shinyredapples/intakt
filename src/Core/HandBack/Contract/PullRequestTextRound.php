<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use NoDiscard;

// An interface, because the build task runs the text round before its push and other modules may use only this module's contract.
interface PullRequestTextRound
{
    /**
     * @throws PullRequestTextRoundNotRecorded
     */
    #[NoDiscard]
    public function route(PullRequestTextTask $task, string $taskText, string $body): PullRequestTextOutcome|FailedPullRequestText;
}
