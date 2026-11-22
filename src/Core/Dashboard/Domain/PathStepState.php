<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum PathStepState: string
{
    case Done = 'done';
    case Now = 'now';
    case Next = 'next';
}
