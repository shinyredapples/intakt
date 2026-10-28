<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum Severity: string
{
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
}
