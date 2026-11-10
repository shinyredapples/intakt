<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

enum ProjectMode: string
{
    case Live = 'live';
    case Shadow = 'shadow';
    case ReviewerForLoop = 'reviewer-for-loop';
}
