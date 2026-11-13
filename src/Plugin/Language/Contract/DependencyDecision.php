<?php

declare(strict_types=1);

namespace Intakt\Plugin\Language\Contract;

final readonly class DependencyDecision
{
    /**
     * @param list<DependencyFinding> $findings
     * @param list<string>            $newPackages
     */
    private function __construct(
        public array $findings,
        public array $newPackages,
    ) {
    }

    /**
     * @param list<DependencyFinding> $findings
     * @param list<string>            $newPackages
     */
    public static function of(array $findings, array $newPackages): self
    {
        $unique = [];
        foreach ($findings as $finding) {
            $unique[strlen($finding->subject).':'.$finding->subject.$finding->reason] = $finding;
        }

        $newPackages = array_values(array_unique($newPackages));
        sort($newPackages);

        return new self(array_values($unique), $newPackages);
    }

    // A file that cannot be read hides what changed, so it needs the owner like any change the rules do not clear.
    public static function unreadable(string $subject, string $reason): self
    {
        return new self([new DependencyFinding($subject, $reason)], []);
    }

    // One decision per merge base, because a criss-cross history has several and no choice of one may hide a package.
    public static function combined(self $first, self ...$others): self
    {
        $findings = $first->findings;
        $newPackages = $first->newPackages;
        foreach ($others as $other) {
            $findings = [...$findings, ...$other->findings];
            $newPackages = [...$newPackages, ...$other->newPackages];
        }

        return self::of($findings, $newPackages);
    }

    public function needsOwner(): bool
    {
        return [] !== $this->findings;
    }
}
