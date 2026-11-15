<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ProviderStep
{
    /**
     * @param list<string>   $keyed
     * @param list<string>   $keyless
     * @param list<TierLine> $tiers
     */
    public function __construct(
        public array $keyed,
        public array $keyless,
        public ?string $firstProject,
        public array $tiers,
    ) {
    }

    public function state(): StepState
    {
        return [] === $this->keyed ? StepState::Missing : StepState::Done;
    }

    public function sentence(): string
    {
        if ([] === $this->keyed) {
            return 'No provider has a key yet: set the variable named below for a provider you use.';
        }

        return sprintf('%s %s a key.', implode(' and ', $this->keyed), 1 === count($this->keyed) ? 'has' : 'have');
    }
}
