<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Kept apart from the run, because only a run of the same origin can replace a cancelled one, see docs/design/05-architecture/08-merge.md.
final readonly class CiRunOrigin
{
    public function __construct(
        public string $name,
        public string $event,
        public ?ChangeId $change,
    ) {
    }

    public function isSameAs(self $other): bool
    {
        return $this->name === $other->name
            && $this->event === $other->event
            && $this->change?->value === $other->change?->value;
    }
}
