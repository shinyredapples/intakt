<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the console builds this object per run for #[MapInput], so the container has no reason to hold it.
#[Exclude]
final class RecordDemoInput
{
    #[Argument('The project of the review')]
    public string $project;

    #[Argument('The change that was reviewed, as the review command names it')]
    public string $change;

    #[Argument('The file the recording is written to')]
    public string $recording;
}
