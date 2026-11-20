<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The command is null because one choice is to do nothing, and the outcome is shown with it because a choice must say what happens when picked.
#[Exclude]
final readonly class WaitingChoice
{
    public function __construct(
        public string $outcome,
        public ?string $command,
        public bool $recommended,
    ) {
    }
}
