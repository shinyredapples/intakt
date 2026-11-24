<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\SampleChange;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// One home for whether the sample change can be started, because the setup wizard offers the button and the start route refuses without it.
final readonly class SampleChangeSource
{
    public function __construct(
        private TierResolver $tiers,
        #[Autowire(param: DashboardServices::SAMPLE_REPOSITORY)]
        private ?string $repository,
    ) {
    }

    // Null unless the seed's repository variable is set and the project file still holds the sample project.
    public function source(): ?ChangeSource
    {
        if (!$this->hasRepository() || !$this->tiers->projects()->contains(SampleChange::PROJECT)) {
            return null;
        }

        return new ChangeSource((string) $this->repository, SampleChange::BASE);
    }

    // True on a demo install even before a key lets the seed write the sample project, so the install opens on the setup wizard from its first start.
    public function hasRepository(): bool
    {
        return null !== $this->repository && '' !== $this->repository;
    }
}
