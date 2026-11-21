<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class ChooseBuildModelInput
{
    #[Argument('The project whose rules and tiers to apply')]
    public string $project;

    #[Argument('The task the build is for')]
    public string $task;

    #[Argument('The attempt number, starting at 1')]
    public int $attempt;

    /** @var list<string> */
    #[Option('A label of the build, such as model:strong, repeatable')]
    public array $label = [];

    #[Option('The setback of the earlier round: failed-attempt, blocking-finding or stall')]
    public ?string $setback = null;

    /** @var list<string> */
    #[Option('A path the build touches, repeatable')]
    public array $path = [];

    #[Option('The change (branch) the build continues, whose latest review round may have confirmed an escalation')]
    public ?string $change = null;
}
