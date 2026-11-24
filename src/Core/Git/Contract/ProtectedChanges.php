<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

final readonly class ProtectedChanges
{
    private function __construct(
        public Paths $paths,
        public ?string $unreadable,
    ) {
    }

    public static function touching(Paths $paths): self
    {
        return new self($paths, null);
    }

    public static function unreadable(string $reason): self
    {
        return new self(new Paths(), $reason);
    }

    // A change the check could not read counts as protected, because what it could not see may be exactly the protected part.
    public function touchesProtectedPaths(): bool
    {
        return null !== $this->unreadable || !$this->paths->isEmpty();
    }
}
