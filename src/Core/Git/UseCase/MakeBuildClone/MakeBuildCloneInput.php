<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class MakeBuildCloneInput
{
    #[Argument('The project name')]
    public string $project;

    #[Argument('The task\'s branch; one the mirror does not hold yet starts at main')]
    public string $branch;

    #[Argument('The folder to clone into, which must not exist yet')]
    public string $directory;

    #[Option('A file holding the task\'s text, read for a "Source: <branch>@<sha>" line')]
    public ?string $task = null;

    #[Option('The task\'s author has write access, so its Source line adds the commit as refs/source/<branch>')]
    public bool $trustedAuthor = false;
}
