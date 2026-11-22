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
    // A bound on the poll of one round only, because every poll of a complete round lists the transport and parked publications pile up there; a parked judging past it leaves that round to the upper wait, which ends the poll as no outcome in time.
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

    // Every round whose judging failed, recorded or parked, read once from each place, because a caller deciding many rounds under a lock should not list the failure transport once per round; the whole transport, because recurrence settlement has no upper wait, so a parked judging it missed would hold back every later round of its change.
    /**
     * @return array<string, true>
     */
    public function failedRounds(): array
    {
        $failed = $this->parkedRounds(null);

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgingFailed) as $recorded) {
            $round = $this->payloads->of($recorded, JudgingFailed::class)?->round;

            if (null !== $round) {
                $failed[$round] = true;
            }
        }

        return $failed;
    }

    // The first record of each round, read once, so a caller deciding many rounds does not read the ledger once per round.
    /**
     * @return array<string, DateTimeImmutable>
     */
    public function recordedTimes(): array
    {
        $times = [];

        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgingFailed) as $recorded) {
            $round = $this->payloads->of($recorded, JudgingFailed::class)?->round;

            if (null !== $round && !isset($times[$round])) {
                $times[$round] = $recorded->recordedAt;
            }
        }

        return $times;
    }

    // Bounded like isParked(), because a caller that decides many rounds reads the transport once and must not list it whole.
    /**
     * @return array<string, true>
     */
    public function parked(): array
    {
        return $this->parkedRounds(self::PARKED_MESSAGES_READ);
    }

    // The failure transport alone, so a caller that already read the ledger record does not read the ledger a second time.
    public function isParked(string $roundId): bool
    {
        return isset($this->parkedRounds(self::PARKED_MESSAGES_READ)[$roundId]);
    }

    // A transport that cannot be listed shows no failure, because reading it with get() would hand its messages out.
    /**
     * @return array<string, true>
     */
    private function parkedRounds(?int $limit): array
    {
        if (!$this->failed instanceof ListableReceiverInterface) {
            return [];
        }

        $parked = [];

        // Bounded rather than filtered by class, because the transport's serializer stores no message type a query could select on; the Doctrine transport reads a bounded list in no set order.
        foreach ($this->failed->all($limit) as $envelope) {
            $message = $envelope->getMessage();

            // A message that deserialized without its round id names no round, and one such message must not stop the judging of other changes.
            if ($message instanceof JudgeRound && new ReflectionProperty($message, 'roundId')->isInitialized($message)) {
                $parked[$message->roundId] = true;
            }
        }

        return $parked;
    }
}
