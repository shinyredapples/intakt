<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Review\Contract\Labels;

enum TaskKind: string
{
    case Task = 'task';
    case Bug = 'bug';

    public static function fromLabels(Labels $labels): self
    {
        return $labels->contains(self::Bug->value) ? self::Bug : self::Task;
    }
}
