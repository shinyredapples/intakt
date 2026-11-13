<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

final readonly class PullRequest
{
    public function __construct(public ?DateTimeImmutable $merged)
    {
    }
}
