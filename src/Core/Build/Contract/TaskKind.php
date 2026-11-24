<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

enum TaskKind: string
{
    case Task = 'task';
    case Bug = 'bug';

    /**
     * @param list<string> $labels
     */
    public static function fromLabels(array $labels): self
    {
        return in_array(self::Bug->value, $labels, true) ? self::Bug : self::Task;
    }
}
