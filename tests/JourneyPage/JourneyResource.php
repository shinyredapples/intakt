<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

enum JourneyResource: string
{
    case Milestones = 'milestones';
    case Issues = 'issues';
    case PullRequests = 'pulls';

    public function path(string $repository): string
    {
        return 'repos/'.$repository.'/'.$this->value.'?state=all&per_page=100';
    }
}
