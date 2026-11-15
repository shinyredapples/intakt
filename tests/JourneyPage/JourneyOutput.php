<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class JourneyOutput
{
    /**
     * @param array<string, string> $drawings each SVG by its name in docs/journey/
     */
    public function __construct(public string $page, public array $drawings)
    {
    }
}
