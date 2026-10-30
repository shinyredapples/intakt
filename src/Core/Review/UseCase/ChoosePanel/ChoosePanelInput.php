<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ChoosePanel;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class ChoosePanelInput
{
    #[Argument('The project whose panel to choose')]
    public string $project;

    #[Option('Added and removed lines of the described change')]
    public int $lines = 0;

    /** @var list<string> */
    #[Option('A changed path of the described change, repeatable')]
    public array $path = [];

    /** @var list<string> */
    #[Option('A label of the described change, repeatable')]
    public array $label = [];
}
