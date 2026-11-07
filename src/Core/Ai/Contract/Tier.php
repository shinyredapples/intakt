<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

enum Tier: string
{
    case Strong = 'strong';
    case Standard = 'standard';
    case Light = 'light';

    // Because a full verdict with its findings must fit, and the strong tier writes the longest reviews.
    public function defaultMaxOutput(): int
    {
        return match ($this) {
            self::Strong => 16000,
            self::Standard => 8000,
            self::Light => 4000,
        };
    }
}
