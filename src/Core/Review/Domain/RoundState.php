<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

enum RoundState: string
{
    case Collecting = 'collecting';
    case Complete = 'complete';
    case Judged = 'judged';
}
