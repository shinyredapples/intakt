<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class BuildTaskInput
{
    #[Argument('The project whose issue to build')]
    public string $project;

    #[Argument('The number of the issue, which is the task')]
    public int $issue;
}
