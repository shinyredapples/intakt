<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Watchdog\Contract\CommitNameNotFull;
use Intakt\Core\Watchdog\Contract\Halts;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\OwnerCard;
use Intakt\Core\Watchdog\Domain\HaltClearedPayload;
use Intakt\Core\Watchdog\Domain\HaltPayload;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

// Halts and clears are only appended, so the standing cards are every halt no clear names.
#[AsAlias(Halts::class)]
final readonly class LedgerHalts implements Halts
{
    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    #[Override]
    public function standing(): array
    {
        $cleared = $this->clearedCards();

        return array_values(array_filter($this->raised(), static fn (OwnerCard $card): bool => !in_array($card->id->toRfc4122(), $cleared, true)));
    }

    /**
     * @return list<OwnerCard>
     */
    public function standingOf(string $project): array
    {
        return array_values(array_filter($this->standing(), static fn (OwnerCard $card): bool => $card->halts($project)));
    }

    // Cleared cards count too, because the owner already checked that commit and a new card for it would halt the project again for nothing.
    public function wasRaised(string $project, MainViolation $violation): bool
    {
        return array_any($this->raised(), static fn (OwnerCard $card): bool => null !== $card->violation && $card->project === $project && $card->violation->commit === $violation->commit && $card->violation->violation === $violation->violation);
    }

    /**
     * @throws HaltNotRecorded
     */
    public function raise(string $project, MainViolation $violation): void
    {
        $payload = ['project' => $project, 'commit' => $violation->commit, 'violation' => $violation->violation->value];
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::MainHalted, json_encode($payload, JSON_THROW_ON_ERROR)));
        if (!$result->succeeded()) {
            throw HaltNotRecorded::raising($violation->commit, (string) $result->failure);
        }
    }

    /**
     * @throws HaltNotRecorded
     */
    public function clear(OwnerCard $card): void
    {
        $id = $card->id->toRfc4122();
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::HaltCleared, json_encode(['card' => $id], JSON_THROW_ON_ERROR)));
        if (!$result->succeeded()) {
            throw HaltNotRecorded::clearing($id, (string) $result->failure);
        }
    }

    /**
     * @return list<OwnerCard>
     */
    private function raised(): array
    {
        $cards = [];
        foreach ($this->ledger->readOfKind(LedgerEventKind::MainHalted) as $recorded) {
            $cards[] = $this->cardOf($recorded);
        }

        return $cards;
    }

    private function cardOf(RecordedEvent $recorded): OwnerCard
    {
        $payload = $this->payloads->of($recorded, HaltPayload::class);
        if (!$payload instanceof HaltPayload) {
            return OwnerCard::unreadable($recorded->id, $recorded->recordedAt);
        }

        try {
            return OwnerCard::of($recorded->id, $payload->project, new MainViolation($payload->commit, $payload->violation), $recorded->recordedAt);
        } catch (CommitNameNotFull) {
            return OwnerCard::unreadable($recorded->id, $recorded->recordedAt);
        }
    }

    // A clear that does not read lifts nothing, so the halt it meant stays in effect.
    /**
     * @return list<string>
     */
    private function clearedCards(): array
    {
        $cleared = [];
        foreach ($this->ledger->readOfKind(LedgerEventKind::HaltCleared) as $recorded) {
            $payload = $this->payloads->of($recorded, HaltClearedPayload::class);
            if ($payload instanceof HaltClearedPayload) {
                $cleared[] = $payload->card;
            }
        }

        return $cleared;
    }
}
