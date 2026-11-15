<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\SampleChange;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// One home for whether the sample change can be started, because the first-run page offers the button and the start route refuses without it.
final readonly class SampleChangeSource
{
    public function __construct(
        private TierResolver $tiers,
        #[Autowire(env: 'default::INTAKT_SAMPLE_REPOSITORY')]
        private ?string $repository,
    ) {
    }

    // Null unless the seed's repository variable is set and the project file still holds the sample project.
    public function source(): ?ChangeSource
    {
        if (null === $this->repository || '' === $this->repository || !in_array(SampleChange::PROJECT, $this->tiers->projects(), true)) {
            return null;
        }

        return new ChangeSource($this->repository, SampleChange::BASE);
    }
}
