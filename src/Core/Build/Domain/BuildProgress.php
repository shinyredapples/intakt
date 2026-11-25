<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the caller saw of a running build at one moment. The content fingerprint ignores whitespace, so an empty or whitespace-only commit leaves it as it was.
#[Exclude]
final readonly class BuildProgress
{
    /**
     * @param array<string, string> $failingTests failure output by test name; only the names count as progress
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
