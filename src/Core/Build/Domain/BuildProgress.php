<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The caller computes the content fingerprint without whitespace, so that an empty or whitespace-only commit is no progress; failing tests are keyed by name because their output varies by a word for the same failure.
#[Exclude]
final readonly class BuildProgress
{
    /**
     * @param array<string, string> $failingTests
     */
    public function __construct(
        public int $tokensUsed,
        public int $taskTokensUsed,
        public string $contentFingerprint,
        public array $failingTests,
        public bool $checkRunning,
    ) {
    }

    public function differsFrom(self $earlier): bool
    {
        return $this->contentFingerprint !== $earlier->contentFingerprint
            || $this->failingTestNames() !== $earlier->failingTestNames();
    }

    /**
     * @return list<string>
     */
    private function failingTestNames(): array
    {
        $names = array_map(strval(...), array_keys($this->failingTests));
        sort($names);

        return $names;
    }
}
