<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class PageRegion
{
    public function __construct(public string $name, public string $text)
    {
    }
}
