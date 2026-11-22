<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\JudgingFailed;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

// Both places are read, because the failure message records the failure in the ledger, and Messenger parks the judging only when that message could not be dispatched.
final readonly class FailedJudgings
{
    // A bound, because every poll of a complete round lists the transport and parked publications pile up there; the ledger record is the main signal, so a parked judging past it only leaves its round to the upper wait.
    public const int PARKED_MESSAGES_READ = 500;

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

    // Every round whose judging failed, recorded or parked, read once from each place, because a caller deciding many rounds under a lock should not list the failure transport once per round.
    /**
     * @return array<string, true>
     */
    public function failedRounds(): array
    {
        $failed = $this->parkedRounds();

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgingFailed) as $recorded) {
            $round = $this->payloads->of($recorded, JudgingFailed::class)?->round;

            if (null !== $round) {
                $failed[$round] = true;
            }
        }

        return $failed;
    }

    private function isParked(string $roundId): bool
    {
        return isset($this->parkedRounds()[$roundId]);
    }

    // A transport that cannot be listed shows no failure, because reading it with get() would hand its messages out.
    /**
     * @return array<string, true>
     */
    private function parkedRounds(): array
    {
        if (!$this->failed instanceof ListableReceiverInterface) {
            return [];
        }

        $parked = [];

        // Bounded rather than filtered by class, because the transport's serializer stores no message type a query could select on; the Doctrine transport reads a bounded list in no set order.
        foreach ($this->failed->all(self::PARKED_MESSAGES_READ) as $envelope) {
            $message = $envelope->getMessage();

            // A message that deserialized without its round id names no round, and one such message must not stop the judging of other changes.
            if ($message instanceof JudgeRound && new ReflectionProperty($message, 'roundId')->isInitialized($message)) {
                $parked[$message->roundId] = true;
            }
        }

        return $parked;
    }
}
