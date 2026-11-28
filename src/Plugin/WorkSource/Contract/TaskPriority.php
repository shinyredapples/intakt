<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

// Declared from most to least urgent, because the report lists the mix in that order.
enum TaskPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
