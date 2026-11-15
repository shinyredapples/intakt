<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

enum UsageLane: string
{
    case Build = 'build';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Build => 'building',
            self::Review => 'reviewing',
        };
    }
}
