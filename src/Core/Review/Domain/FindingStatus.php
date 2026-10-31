<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

enum FindingStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
