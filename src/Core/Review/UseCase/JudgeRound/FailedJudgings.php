<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\JudgingFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

// Both places are read, because the failure message records the failure in the ledger, and Messenger parks the judging only when that message could not be dispatched.
final readonly class FailedJudgings
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private ReceiverInterface $failed,
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    public function hasFailed(string $roundId): bool
    {
        return $this->isRecorded($roundId) || $this->isParked($roundId);
    }

    // The ledger record alone, which commits together with the queued publication, so it tells whether the round's outcome is published already.
    public function isRecorded(string $roundId): bool
    {
        return $this->recordedAt($roundId) instanceof DateTimeImmutable;
    }

    // When the ledger recorded the round's judging as failed, so a status can place the failure among later outcomes.
    public function recordedAt(string $roundId): ?DateTimeImmutable
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgingFailed) as $recorded) {
            if ($roundId === $this->payloads->of($recorded, JudgingFailed::class)?->round) {
                return $recorded->recordedAt;
            }
        }

        return null;
    }

    // One scan of each place for every round, because a reader asking about many rounds holds the change's lock while it reads.
    /**
     * @return array<string, true>
     */
    public function failedRounds(): array
    {
        $rounds = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgingFailed) as $recorded) {
            $round = $this->payloads->of($recorded, JudgingFailed::class)?->round;

            if (null !== $round) {
                $rounds[$round] = true;
            }
        }

        foreach ($this->parked() as $parked) {
            $message = $parked->getMessage();

            if ($message instanceof JudgeRound) {
                $rounds[$message->roundId] = true;
            }
        }

        return $rounds;
    }

    private function isParked(string $roundId): bool
    {
        foreach ($this->parked() as $parked) {
            if ($this->judges($parked, $roundId)) {
                return true;
            }
        }

        return false;
    }

    // A transport that cannot be listed shows no failure, because reading it with get() would hand its messages out.
    /**
     * @return iterable<Envelope>
     */
    private function parked(): iterable
    {
        return $this->failed instanceof ListableReceiverInterface ? $this->failed->all() : [];
    }

    private function judges(Envelope $parked, string $roundId): bool
    {
        $message = $parked->getMessage();

        return $message instanceof JudgeRound && $message->roundId === $roundId;
    }
}
