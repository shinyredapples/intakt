<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;

// Shadow by either source, so a switch of the project's mode in either direction never lets a shadow verdict count.
final readonly class ShadowRounds
{
    public function __construct(
        private ProjectModes $modes,
        private Ledger $ledger,
    ) {
    }

    public function isShadow(ReviewRound $round): bool
    {
        return ProjectMode::Shadow === $this->modes->of($round->project()) || $this->hasShadowVerdict($round->id()->toRfc4122());
    }

    private function hasShadowVerdict(string $roundId): bool
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::VerdictReceived) as $recorded) {
            $payload = json_decode($recorded->event->payload, true);

            if (is_array($payload) && $roundId === ($payload['round'] ?? null) && ProjectMode::Shadow->value === ($payload['mode'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
