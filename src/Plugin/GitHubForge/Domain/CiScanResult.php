<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use NoDiscard;

final readonly class CiScanResult
{
    /**
     * @param list<CiFinding> $findings
     * @param list<string>    $ciPaths
     */
    public function __construct(
        public array $findings,
        public array $ciPaths,
    ) {
    }

    public function isRisky(): bool
    {
        return [] !== $this->findings;
    }

    public function isCiChange(): bool
    {
        return [] !== $this->ciPaths;
    }

    // A change outside the CI definition runs nothing new on the runner, so only a CI change waits for the owner's approval of the push.
    #[NoDiscard]
    public function holdsThePush(): bool
    {
        return $this->isCiChange() && $this->isRisky();
    }
}
