<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\AdmissionDecision;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\UseCase\AdmitHeads\AdmitHeads;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class LookAtHeads
{
    public function __construct(
        private HeadLocks $locks,
        private AdmitHeads $admit,
        private AdmissionDecision $admission,
        private SettleHead $settle,
        private HeadBackOff $backOff,
    ) {
    }

    public function lookAtDue(WatchedProject $project): void
    {
        $this->locks->whileHoldingDue($project->name, function (array $heads) use ($project): void {
            $this->settle->settleEach($project, $this->decided($project->name, $heads));
        });
    }

    /**
     * @param list<OpenChange> $heads
     *
     * @return list<HeadDecision>
     */
    private function decided(string $project, array $heads): array
    {
        $decisions = array_map(fn (OpenChange $head): ?HeadDecision => $this->backOff->tryAlone($project, $head, fn (): HeadDecision => ($this->admit)($project, $head)), $heads);

        return $this->admission->order(array_values(array_filter($decisions)));
    }
}
