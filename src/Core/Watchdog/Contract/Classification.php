<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class Classification
{
    /**
     * @param list<MainViolation> $violations
     */
    public function __construct(public array $violations)
    {
    }

    public function isClean(): bool
    {
        return [] === $this->violations;
    }
}
