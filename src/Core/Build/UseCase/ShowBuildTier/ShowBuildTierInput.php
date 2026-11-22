<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ShowBuildTier;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// the console builds this object per run for #[MapInput], so the container has no reason to hold it
#[Exclude]
final class ShowBuildTierInput
{
    #[Argument('The project whose rules to apply')]
    public string $project;

    /** @var list<string> */
    #[Option('A label of the build, such as model:strong, repeatable')]
    public array $label = [];

    #[Option('The setback of the earlier round: failed-attempt, blocking-finding or stall')]
    public ?string $setback = null;

    /** @var list<string> */
    #[Option('A path the build touches, repeatable')]
    public array $path = [];

    #[Option('The change the build continues, a branch or a pull-request address (https://github.com/<owner>/<repo>/pull/<n>), whose latest review round may have confirmed an escalation')]
    public ?string $change = null;
}
