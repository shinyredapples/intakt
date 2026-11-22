<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class PathStep
{
    public function __construct(
        public string $label,
        public PathStepState $state,
        public ?StepRole $who = null,
    ) {
    }
}
