<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class BuildTaskInput
{
    #[Argument('The project name')]
    public string $project;

    #[Argument('The number of the issue to build')]
    public string $issue;

    #[Option('The remote to mirror: a path, a file:// or an https:// URL')]
    public ?string $remote = null;

    #[Option('The task\'s branch; one the mirror does not hold yet starts at main')]
    public ?string $branch = null;

    #[Option('The folder to clone into, which must not exist yet')]
    public ?string $directory = null;
}
