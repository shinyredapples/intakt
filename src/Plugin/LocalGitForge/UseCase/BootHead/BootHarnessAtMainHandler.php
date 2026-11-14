<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\BootHead;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\BootHarnessAtMain;
use Intakt\Core\Review\Contract\BootOutcome;

#[AsQueryHandler]
final readonly class BootHarnessAtMainHandler
{
    public function __construct(private InstanceBoot $boot)
    {
    }

    public function __invoke(BootHarnessAtMain $query): BootOutcome
    {
        return $this->boot->of($query->head, $query->main);
    }
}
