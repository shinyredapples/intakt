<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// One answer for the board and the doctor, so the check and its fix text have no second copy (decision D1 of #3826).
final readonly class ForgeConnectionVerdict
{
    private function __construct(
        public ?string $problem,
        public ?string $fix,
    ) {
    }

    public static function passed(): self
    {
        return new self(null, null);
    }

    public static function failed(string $problem, string $fix): self
    {
        return new self($problem, $fix);
    }

    public function passes(): bool
    {
        return null === $this->problem;
    }
}
