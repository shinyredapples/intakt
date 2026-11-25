<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// One answer for the board and the doctor, so the check and its fix text have no second copy (decision D1 of #3826).
final readonly class ForgeConnectionVerdict
{
    private function __construct(
        public ?string $problem,
        public ?string $fix,
        public bool $configured = true,
    ) {
    }

    public static function passed(): self
    {
        return new self(null, null);
    }

    // A pass, because an install without an App (the demo) is set up as meant; told apart so the board does not call a forge it never asked connected.
    public static function withoutAnApp(): self
    {
        return new self(null, null, false);
    }

    public static function failed(string $problem, string $fix): self
    {
        return new self($problem, $fix);
    }

    public function passes(): bool
    {
        return null === $this->problem;
    }

    public function withProblem(string $problem): self
    {
        return new self($problem, $this->fix, $this->configured);
    }
}
