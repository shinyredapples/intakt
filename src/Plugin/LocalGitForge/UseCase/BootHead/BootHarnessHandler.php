<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\BootHead;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\BootHarness;
use Intakt\Core\Review\Contract\BootOutcome;

// Lives in the forge plugin because the commit is read from its repository, which the core review module may not reach.
#[AsQueryHandler]
final readonly class BootHarnessHandler
{
    public function __construct(private InstanceBoot $boot)
    {
    }

    public function __invoke(BootHarness $query): BootOutcome
    {
        return $this->boot->of($query->at, $query->at->commit);
    }
}
