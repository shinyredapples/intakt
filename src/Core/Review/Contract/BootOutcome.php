<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A crash is an outcome like the others, because a boot step that throws would stop the review it only gates.
final readonly class BootOutcome
{
    private function __construct(
        public BootState $state,
        public string $reason,
    ) {
    }

    public static function booted(): self
    {
        return new self(BootState::Booted, '');
    }

    public static function failed(string $reason): self
    {
        return new self(BootState::Failed, $reason);
    }

    public static function crashed(string $reason): self
    {
        return new self(BootState::Crashed, $reason);
    }
}
