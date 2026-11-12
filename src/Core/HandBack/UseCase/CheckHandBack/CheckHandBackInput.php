<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class CheckHandBackInput
{
    #[Argument('A bare repository whose HEAD names main, such as the project\'s mirror')]
    public string $repository;

    #[Argument('The task\'s branch; one the repository does not hold yet starts at main')]
    public string $branch;

    #[Argument('The git bundle the build returned')]
    public string $bundle;

    #[Option('The task asked for a merge of main, so a merge commit that merges main is allowed')]
    public bool $mergeMain = false;
}
