<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Infrastructure;

use Intakt\Core\Shadow\Contract\ShadowServices;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredProjectModes implements ProjectModes
{
    /**
     * @param array<string, string> $modes
     */
    public function __construct(
        #[Autowire(param: ShadowServices::MODES)]
        private array $modes,
    ) {
    }

    public function of(string $project): ProjectMode
    {
        return ProjectMode::from($this->modes[$project] ?? ProjectMode::Live->value);
    }
}
