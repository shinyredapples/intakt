<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

enum UsageVendor: string
{
    case Claude = 'claude';
    case Gpt = 'gpt';

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::Gpt => 'GPT',
        };
    }
}
