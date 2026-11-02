<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Intakt\Plugin\Forge\Contract\CiRunState;
use Intakt\Plugin\Forge\Contract\CiState;

final readonly class AdmissionDecision
{
    public function admissionOf(CiState $ci): HeadAdmission
    {
        // A failure counts while other runs still go: red CI is never reviewed.
        return match (true) {
            $ci->has(CiRunState::Failed) => HeadAdmission::Red,
            $ci->isEmpty(), $ci->has(CiRunState::Unfinished) => HeadAdmission::Pending,
            default => HeadAdmission::Green,
        };
    }

    /**
     * Heads with finished CI first; the sort is stable, so a running head keeps its place among the running ones.
     *
     * @param list<HeadDecision> $decisions
     *
     * @return list<HeadDecision>
     */
    public function order(array $decisions): array
    {
        usort($decisions, static fn (HeadDecision $a, HeadDecision $b): int => (HeadAdmission::Pending === $a->admission) <=> (HeadAdmission::Pending === $b->admission));

        return $decisions;
    }
}
