<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Read from what the builder can write (its output, its commits), so they may only decide whether the build looks idle, never how many tokens it used.
#[Exclude]
final readonly class UntrustedBuildFigures
{
    /**
     * @param array<string, string> $failingTests
     */
    public function __construct(
        public string $contentFingerprint,
        public array $failingTests,
        public bool $checkRunning,
    ) {
    }
}
