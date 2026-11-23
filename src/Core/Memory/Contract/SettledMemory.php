<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

final readonly class SettledMemory
{
    /**
     * @param list<SettledFinding> $settled
     * @param list<SettledFinding> $stale
     */
    private function __construct(
        public array $settled,
        public array $stale,
    ) {
    }

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * @param iterable<SettledFinding> $found
     */
    public static function sortedBy(SettledQuery $query, iterable $found): self
    {
        $settled = [];
        $stale = [];

        foreach ($found as $finding) {
            $current = $query->currentCodeOf($finding);

            // Not asked about: its code is not under review, so it is neither settled nor stale here.
            if (!$current instanceof CodeSnapshot) {
                continue;
            }

            if ($finding->stillMatches($current)) {
                $settled[] = $finding;
            } else {
                $stale[] = $finding;
            }
        }

        return new self($settled, $stale);
    }
}
