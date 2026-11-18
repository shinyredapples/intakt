<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Kept apart from the run, because only a run of the same origin can replace a cancelled or never started one, see docs/design/05-architecture/08-merge.md.
final readonly class CiRunOrigin
{
    /**
     * @param string $definition the forge's own key of the workflow or check that made the run, because two of them can share a display name and an event
     */
    public function __construct(
        public string $name,
        public string $event,
        public ?ChangeId $change,
        public string $definition,
    ) {
    }

    public function isSameAs(self $other): bool
    {
        return $this->definition === $other->definition
            && $this->name === $other->name
            && $this->event === $other->event
            && $this->change?->value === $other->change?->value;
    }
}
