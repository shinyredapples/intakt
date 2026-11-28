<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ReadBuildUsage;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class ReadBuildUsageInput
{
    #[Argument('The build.log a build job handed back')]
    public string $log;
}
