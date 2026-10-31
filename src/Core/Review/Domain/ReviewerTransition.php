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
    case Block = 'block';

    /**
     * @return list<ReviewerState>
     */
    public function fromPlaces(): array
    {
        return match ($this) {
            self::Start => [ReviewerState::Queued],
            self::Receive, self::Fail, self::Requeue => [ReviewerState::Running],
            // Both places, because a message can reach the failure transport while its reviewer waits in the queue (handed back) or still looks running.
            self::Block => [ReviewerState::Queued, ReviewerState::Running],
        };
    }

    public function toPlace(): ReviewerState
    {
        return match ($this) {
            self::Start => ReviewerState::Running,
            self::Receive => ReviewerState::Verdict,
            self::Fail => ReviewerState::Failed,
            self::Requeue => ReviewerState::Queued,
            self::Block => ReviewerState::Blocked,
        };
    }
}
