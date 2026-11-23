<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class Classification
{
    /**
     * @param list<MainViolation> $violations
     * @param list<string>        $ownerPushes
     */
    public function __construct(
        public array $violations,
        // Owner-signed commits no recorded merge covers, kept apart from the violations because the owner's own push is an info event that halts nothing.
        public array $ownerPushes = [],
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->violations;
    }
}
