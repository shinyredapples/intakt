<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

interface GitHubApi
{
    public function pages(JourneyResource $resource): string;
}
