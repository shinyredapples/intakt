<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Whether a project or a review is a recording played back, because a replay is shown like a live review but its verdicts were never given on the change.
enum ReplayMark: string
{
    case Live = 'live';
    case Replay = 'replay';
}
