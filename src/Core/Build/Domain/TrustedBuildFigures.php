<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Apart from the builder's figures, because the runaway ceiling and the per-task total may rest only on what the builder cannot forge: the CLI's token report and the orchestrator's clock.
#[Exclude]
final readonly class TrustedBuildFigures
{
    public function __construct(
        public int $tokensUsed,
        public DateTimeImmutable $observedAt,
    ) {
    }
}
