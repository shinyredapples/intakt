<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum FixableBy: string
{
    case Builder = 'builder';
    case Owner = 'owner';
}
