<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the console builds this object per run for #[MapInput], so the container has no reason to hold it.
#[Exclude]
final class ReplayDemoInput
{
    #[Argument('The recording file intakt:demo:record wrote')]
    public string $recording;

    #[Option('How many times faster than recorded the rows are written')]
    public float $factor = 1.0;
}
