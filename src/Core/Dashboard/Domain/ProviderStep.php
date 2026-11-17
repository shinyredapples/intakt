<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ai\Contract\ConfiguredKey;

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
        public ?ConfiguredKey $kept = null,
        public bool $keptUnreadable = false,
    ) {
    }

    public function state(): StepState
    {
        return [] === $this->keyed && !$this->kept instanceof ConfiguredKey ? StepState::Missing : StepState::Done;
    }

    // One field takes the first key, so it shows while no provider has a key and none is kept; the kept file blocks a second key even when it cannot be read.
    public function offersKeyForm(): bool
    {
        return [] === $this->keyed && !$this->kept instanceof ConfiguredKey && !$this->keptUnreadable;
    }

    public function sentence(): string
    {
        if ($this->keptUnreadable) {
            return 'The key kept in the browser cannot be read: fix or remove it, or set the variable named below for a provider you use.';
        }

        if ([] === $this->keyed && $this->kept instanceof ConfiguredKey) {
            return sprintf('The %s key ending in %s is kept. Restart Intakt to use it.', $this->kept->kind->value, $this->kept->lastCharacters);
        }

        if ([] === $this->keyed) {
            return 'No provider has a key yet: paste one below, or set the variable named below for a provider you use.';
        }

        $keyed = $this->keyed;
        $last = array_pop($keyed);
        $names = [] === $keyed ? $last : sprintf('%s and %s', implode(', ', $keyed), $last);

        return sprintf('%s %s a key.', $names, 1 === count($this->keyed) ? 'has' : 'have');
    }
}
