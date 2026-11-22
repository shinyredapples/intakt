<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Intakt\Plugin\Forge\Contract\CiRun;
use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;

final readonly class AdmissionDecision
{
    public function admissionOf(CiState $ci): HeadAdmission
    {
        $ci = $this->withoutReplacedRuns($ci);

        // Red wins over pending, because a failure is final while other runs still go.
        return match (true) {
            $ci->has(CiRunState::Failed) => HeadAdmission::Red,
            $ci->isEmpty(), $ci->has(CiRunState::Queued), $ci->has(CiRunState::Unfinished), $ci->has(CiRunState::Cancelled) => HeadAdmission::Pending,
            default => HeadAdmission::Green,
        };
    }

    // Stable sort, so that a running head keeps its place among the running ones.
    /**
     * @param list<HeadDecision> $decisions
     *
     * @return list<HeadDecision>
     */
    public function order(array $decisions): array
    {
        usort($decisions, static fn (HeadDecision $a, HeadDecision $b): int => (HeadAdmission::Pending === $a->admission) <=> (HeadAdmission::Pending === $b->admission));

        return $decisions;
    }

    // Dropped because a newer run of the same origin on the same head replaced it, see docs/design/05-architecture/08-merge.md.
    private function withoutReplacedRuns(CiState $ci): CiState
    {
        $kept = array_filter($ci->runs, static fn (CiRun $run): bool => !in_array($run->state, [CiRunState::Cancelled, CiRunState::Queued], true)
            || !array_any($ci->runs, static fn (CiRun $other): bool => $other->isNewerRunOf($run)));

        return new CiState($ci->head, array_values($kept));
    }
}
