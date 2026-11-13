<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

interface GitHubApi
{
    /**
     * @return string the JSON of `gh api --paginate --slurp`: a list of pages
     */
    public function pages(JourneyResource $resource): string;
}
