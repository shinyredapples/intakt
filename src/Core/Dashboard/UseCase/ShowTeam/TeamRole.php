<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

final readonly class TeamRole
{
    public const string BUILD_RUNTIME_MARK = '(the build runtime cannot run it)';

    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public string $name,
        public BuildRuntime $buildRuntime = BuildRuntime::Runs,
    ) {
    }

    public function inWords(): string
    {
        return BuildRuntime::Runs === $this->buildRuntime ? $this->name : $this->name.' '.self::BUILD_RUNTIME_MARK;
    }
}
