<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the CLI's stream-json events of a running build show, because neither the clone nor the test runs are visible from outside the container; a malformed line is skipped.
#[Exclude]
final readonly class BuildLogReading
{
    /**
     * @param array<string, string> $failingTests
     */
    public function __construct(
        public int $tokens,
        public string $contentFingerprint,
        public array $failingTests,
        public bool $checkRunning,
    ) {
    }

    public static function of(string $log): self
    {
        $tally = new BuildLogTally();

        foreach (explode("\n", $log) as $line) {
            $event = BuildLogEvents::decode($line);

            if (null !== $event) {
                $tally->take($event);
            }
        }

        return $tally->reading();
    }
}
