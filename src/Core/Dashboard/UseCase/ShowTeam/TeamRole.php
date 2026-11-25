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
        public bool $buildRuntimeCanRun = true,
    ) {
    }

    public function inWords(): string
    {
        return $this->buildRuntimeCanRun ? $this->name : $this->name.' '.self::BUILD_RUNTIME_MARK;
    }
}
