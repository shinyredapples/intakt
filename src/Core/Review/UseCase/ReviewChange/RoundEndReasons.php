<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

// The plain words of a blocked end, in one place, because the command and the tool print the same reason.
final readonly class RoundEndReasons
{
    public const string JUDGING_FAILED = 'the judging failed';

    public const string NO_OUTCOME_IN_TIME = 'no outcome in time';

    public function judgingFailed(): string
    {
        return self::JUDGING_FAILED;
    }

    public function noOutcomeInTime(int $seconds): string
    {
        return self::NO_OUTCOME_IN_TIME.': the round did not end within '.$seconds.' seconds; if no worker runs, start one with "messenger:consume async", then review again';
    }
}
