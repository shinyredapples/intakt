<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What a tool call of the session asked for, kept until its result arrives, because only the result tells whether a commit was made or a test run went to the background.
#[Exclude]
final readonly class BuildLogCall
{
    public function __construct(
        public bool $runsTests,
        public bool $triesToCommit,
        public bool $sentToBackground,
        public string $shell,
        public bool $killsShell,
    ) {
    }

    /**
     * @param array<mixed> $input
     */
    public static function of(string $tool, array $input): self
    {
        $command = BuildLogEvents::textAt($input, 'command');

        return new self(
            BuildLogEvents::runsTests($tool, $command),
            BuildLogEvents::triesToCommit($tool, $command),
            true === ($input['run_in_background'] ?? null),
            BuildLogEvents::shellLookedAt($input),
            str_starts_with($tool, 'Kill'),
        );
    }
}
