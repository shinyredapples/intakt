<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class ReviewChangeInput
{
    #[Argument('The branch under review')]
    public string $change;

    #[Option('The project whose review panel the change gets')]
    public string $project = '';

    #[Option('The text of the task the branch builds')]
    public string $task = '';

    #[Option('The number the new round must have, as a check against a round that started meanwhile')]
    public ?int $round = null;

    #[Option('The check command the head must pass')]
    public string $checkCommand = 'bin/check';

    #[Option('The output format: text or json')]
    public OutputFormat $format = OutputFormat::Text;

    #[Option('The local repository, relative to the working directory or absolute')]
    public string $repository = '.';

    #[Option('The branch the change is measured against')]
    public string $base = 'main';
}
