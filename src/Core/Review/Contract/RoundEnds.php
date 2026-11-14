<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The ways a round ends for a caller that waited for it, one set for every door (the command, the MCP tool, the dashboard), so none of them words an end of its own.
enum RoundEnds: string
{
    case Judged = 'judged';
    case JudgingFailed = 'judging_failed';
    case NoOutcomeInTime = 'no_outcome_in_time';
}
