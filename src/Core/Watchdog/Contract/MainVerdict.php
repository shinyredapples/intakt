<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class MainVerdict
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        public string $head,
        public array $violations,
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->violations;
    }
}
