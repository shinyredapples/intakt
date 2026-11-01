<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\CheckVerdictIdentity;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class CheckVerdictIdentityInput
{
    #[Argument('The project whose review panel the change gets')]
    public string $project;

    #[Argument('The branch under review')]
    public string $branch;

    #[Argument('The text of the task the branch builds')]
    public string $task;

    #[Argument('The check command the head must pass')]
    public string $checkCommand;

    #[Option('The identity a verdict recorded, to tell whether it still counts')]
    public ?string $reviewed = null;

    #[Option('The local repository, relative to the working directory or absolute')]
    public string $repository = '.';

    #[Option('The branch the change is measured against')]
    public string $base = 'main';
}
