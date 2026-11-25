<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded from the container because each run of a doctor check builds its own answer.
#[Exclude]
final readonly class CheckVerdict
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
