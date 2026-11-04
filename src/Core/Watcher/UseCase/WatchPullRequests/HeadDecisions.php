<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class HeadDecisions
{
    public function __construct(
        private AdmitHeads $admit,
        private AdmissionDecision $admission,
        private HeadBackOff $backOff,
    ) {
    }

    /**
     * @param list<OpenChange> $heads
     *
     * @return list<HeadDecision>
     */
    public function of(string $project, array $heads): array
    {
        $decisions = array_map(fn (OpenChange $head): ?HeadDecision => $this->backOff->tryAlone($project, $head, fn (): HeadDecision => ($this->admit)($project, $head)), $heads);

        return $this->admission->order(array_values(array_filter($decisions)));
    }
}
