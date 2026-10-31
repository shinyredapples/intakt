<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// The workflow configuration is built from these cases, so the names and the places have one home.
enum ReviewerTransition: string
{
    case Start = 'start';
    case Receive = 'receive';
    case Fail = 'fail';
    case Requeue = 'requeue';

    public function fromPlace(): ReviewerState
    {
        return match ($this) {
            self::Start => ReviewerState::Queued,
            self::Receive, self::Fail, self::Requeue => ReviewerState::Running,
        };
    }

    public function toPlace(): ReviewerState
    {
        return match ($this) {
            self::Start => ReviewerState::Running,
            self::Receive => ReviewerState::Verdict,
            self::Fail => ReviewerState::Failed,
            self::Requeue => ReviewerState::Queued,
        };
    }
}
