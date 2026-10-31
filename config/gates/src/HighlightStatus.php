<?php

declare(strict_types=1);

namespace Intakt\Gates;

enum HighlightStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in progress';
    case Done = 'done';
}
