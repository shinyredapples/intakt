<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// What the owner may pick and what happens then; the command is null where the choice is to do nothing.
final readonly class WaitingChoice
{
    public function __construct(
        public string $outcome,
        public ?string $command,
        public bool $recommended,
    ) {
    }
}
