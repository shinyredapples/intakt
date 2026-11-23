<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class CheckPullRequestTextInput
{
    #[Argument('A file holding the task\'s text, whose Evidence items the pull request text must reference')]
    public string $task;

    #[Argument('The pull-request.md the build left in its output directory: the title on the first line, then the body')]
    public string $pullRequestText;

    #[Argument('The task\'s repository, such as the project\'s mirror; with the branch it names the task whose misses are counted')]
    public string $repository;

    #[Argument('The task\'s branch')]
    public string $branch;
}
