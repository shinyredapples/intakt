<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;

// Shadow by either source, so a switch of the project's mode in either direction never lets a shadow verdict count.
final readonly class ShadowRounds
{
    public function __construct(
        private ProjectModes $modes,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    public function isShadow(ReviewRound $round): bool
    {
        return ProjectMode::Shadow === $this->modes->of($round->project()) || $this->hasShadowVerdict($round->id()->toRfc4122());
    }

    private function hasShadowVerdict(string $roundId): bool
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::VerdictReceived) as $recorded) {
            $received = $this->payloads->of($recorded, VerdictReceived::class);

            if ($roundId === $received?->round && ProjectMode::Shadow === $received->mode) {
                return true;
            }
        }

        return false;
    }
}
