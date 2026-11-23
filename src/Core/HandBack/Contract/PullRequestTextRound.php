<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use InvalidArgumentException;
use NoDiscard;

// An interface, because the build task runs the text round before its push and other modules may use only this module's contract.
interface PullRequestTextRound
{
    /**
     * @throws InvalidArgumentException
     * @throws PullRequestTextRoundNotRecorded
     */
    #[NoDiscard]
    public function route(string $repository, string $branch, string $taskText, string $body): PullRequestTextOutcome|FailedPullRequestText;
}
