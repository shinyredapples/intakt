<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class RunBuildJobInput
{
    #[Argument('The project whose tier map picks the model')]
    public string $project;

    #[Argument('The build clone made with intakt:build:clone')]
    public string $clone;

    #[Argument('The task\'s branch, the one the build clone checked out')]
    public string $branch;

    #[Argument('A file holding the task\'s text')]
    public string $task;

    #[Argument('The project\'s job image the build runs in')]
    public string $image;

    #[Argument('Where to write the bundle the build hands back')]
    public string $bundle;

    #[Option('The capability tier the planner picked: strong, standard or light')]
    public string $tier = 'standard';

    /** @var list<string> */
    #[Option('A maintainer\'s decision on the task; repeat for each, in order')]
    public array $decision = [];
}
