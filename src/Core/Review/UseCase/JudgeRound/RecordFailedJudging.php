<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Platform\Contract\CommandMessage;
use Symfony\Component\Messenger\Stamp\DispatchOnFailureStamp;

// Travels inside the DispatchOnFailureStamp of the round's JudgeRound, so Messenger dispatches it once that judging failed for good.
final readonly class RecordFailedJudging implements CommandMessage
{
    public function __construct(public string $roundId)
    {
    }

    public static function onFailureOf(string $roundId): DispatchOnFailureStamp
    {
        return new DispatchOnFailureStamp(new self($roundId));
    }
}
