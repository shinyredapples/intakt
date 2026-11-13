<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

enum TierReason: string
{
    case SensitivePath = 'sensitive-path';
    case Label = 'label';
    case Default = 'default';
}
