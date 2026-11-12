<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Infrastructure;

use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Core\ReviewerForLoop\Contract\ProjectModes;
use Intakt\Core\ReviewerForLoop\Contract\ReviewerForLoopServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredProjectModes implements ProjectModes
{
    /**
     * @param array<string, string> $modes
     */
    public function __construct(
        #[Autowire(param: ReviewerForLoopServices::MODES)]
        private array $modes,
    ) {
    }

    public function of(string $project): ProjectMode
    {
        return ProjectMode::from($this->modes[$project] ?? ProjectMode::Live->value);
    }
}
