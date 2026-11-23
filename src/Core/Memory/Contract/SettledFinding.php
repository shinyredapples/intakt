<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class SettledFinding
{
    public function __construct(
        public string $project,
        public string $text,
        public string $reason,
        public CodeSnapshot $code,
    ) {
    }

    public function describes(CodeSnapshot $current): bool
    {
        return $this->code->sameSpotAs($current);
    }

    public function stillMatches(CodeSnapshot $current): bool
    {
        return $this->code->hash === $current->hash;
    }
}
