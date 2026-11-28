<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class BuildObservation
{
    public function __construct(
        public TrustedBuildFigures $trusted,
        public UntrustedBuildFigures $untrusted,
    ) {
    }

    // Both token figures from the trusted part, because they decide the runaway ceiling and the per-task total; the task's figure is this build's alone until the continuations' tokens are read from the ledger.
    #[NoDiscard]
    public function progress(): BuildProgress
    {
        return new BuildProgress(
            $this->trusted->tokensUsed,
            $this->trusted->tokensUsed,
            $this->untrusted->contentFingerprint,
            $this->untrusted->failingTests,
            $this->untrusted->checkRunning,
        );
    }
}
