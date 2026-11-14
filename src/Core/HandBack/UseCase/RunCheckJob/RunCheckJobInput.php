<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class RunCheckJobInput
{
    #[Argument('A bare repository whose HEAD names main, such as the project\'s mirror')]
    public string $repository;

    #[Argument('The task\'s branch; one the repository does not hold yet starts at main')]
    public string $branch;

    #[Argument('The git bundle the build returned')]
    public string $bundle;

    #[Argument('The project\'s job image the check runs in')]
    public string $image;

    #[Option('The project\'s check command, run instead of the gate\'s own steps')]
    public ?string $checkCommand = null;

    /** @var list<string> */
    #[Option('A path the task\'s plan names as removed or rewritten, so it may lose its content; repeat for each')]
    public array $rewritten = [];

    #[Option('The task asked for a merge of main, so a merge commit that merges main is allowed')]
    public bool $mergeMain = false;
}
