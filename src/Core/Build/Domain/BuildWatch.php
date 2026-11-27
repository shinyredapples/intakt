<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use DateTimeImmutable;
use NoDiscard;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The caller keeps this between observations, because the rule is a shared service that holds no state of one build.
#[Exclude]
final readonly class BuildWatch
{
    public function __construct(
        public BuildLimits $limits,
        public BuildProgress $latest,
        public DateTimeImmutable $lastProgressAt,
    ) {
    }

    // A check counts as progress until it is seen ended, so a long test run is never idle time; it ran some time since the last look, so that look still counts.
    #[NoDiscard]
    public function seeing(BuildProgress $progress, DateTimeImmutable $now): self
    {
        if ($progress->checkRunning || $this->latest->checkRunning || $progress->differsFrom($this->latest)) {
            return clone($this, ['latest' => $progress, 'lastProgressAt' => $now]);
        }

        return clone($this, ['latest' => $progress]);
    }
}
