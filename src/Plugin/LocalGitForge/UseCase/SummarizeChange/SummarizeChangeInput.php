<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\SummarizeChange;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class SummarizeChangeInput
{
    #[Argument('The branch to summarize')]
    public string $branch;

    #[Option('The local repository, relative to the working directory or absolute')]
    public string $repository = '.';

    #[Option('The branch the change is measured against')]
    public string $base = 'main';
}
