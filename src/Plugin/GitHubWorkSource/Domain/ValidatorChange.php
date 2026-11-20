<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// The state a listing was read against and the validator stored after it, so a listing is written only over the state it was read against.
final readonly class ValidatorChange
{
    public function __construct(
        public IntakeState $from,
        public ?string $to,
    ) {
    }
}
