<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum ReviewState: string
{
    case InProgress = 'in progress';
    case Judged = 'judged';
}
