<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

enum ProjectMode: string
{
    case Live = 'live';
    case ReviewerForLoop = 'reviewer-for-loop';
}
