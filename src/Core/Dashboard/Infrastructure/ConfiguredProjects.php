<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Watcher\Contract\WatcherServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// One source for the project cards and the project filter, because a card links to the filter's choice of its project and two lists could drift apart.
final readonly class ConfiguredProjects
{
    /**
     * @param array<string, mixed> $watched
     */
    public function __construct(
        private TierResolver $tiers,
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $watched,
    ) {
    }

    // Names are strings again here, because PHP turns a project name of digits into an integer key.
    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_map(strval(...), array_keys($this->watched)), ...$this->tiers->projects()]));
    }
}
