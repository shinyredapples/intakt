<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class Classification
{
    /**
     * @param list<MainViolation> $violations
     * @param list<CommitName>    $ownerPushes
     * @param list<CommitName>    $peerMerges
     */
    public function __construct(
        public array $violations,
        // Owner-signed commits no recorded merge covers, kept apart from the violations because the owner's own push is an info event that halts nothing.
        public array $ownerPushes = [],
        // Commits signed by the peer loop's key, kept apart from the owner pushes because the peer's merge went through its own gate, so it gets no owner notice.
        public array $peerMerges = [],
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->violations;
    }
}
