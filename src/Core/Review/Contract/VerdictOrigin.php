<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Who gave a verdict, because a finding id can be named by a model as well as by Intakt and so cannot tell a reviewer's blocked from Intakt's own.
enum VerdictOrigin: string
{
    case Reviewer = 'reviewer';
    case Intakt = 'intakt';
}
