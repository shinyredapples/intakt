<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ApprovePush;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class PushApprovalInput
{
    #[Argument('The git directory of the repository, a bare mirror or a .git folder, whose HEAD names main')]
    public string $repository;

    #[Argument('The head branch or commit')]
    public string $head;

    #[Option('The project whose GitHub repository holds the issue')]
    public string $project = '';

    #[Option('The issue of the task the head was built for')]
    public int $issue = 0;
}
