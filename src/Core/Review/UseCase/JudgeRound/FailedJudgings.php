<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

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
        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgingFailed) as $recorded) {
            if ($roundId === $this->payloads->of($recorded, JudgingFailed::class)?->round) {
                return true;
            }
        }

        return false;
    }

    // A transport that cannot be listed shows no failure, because reading it with get() would hand its messages out.
    private function isParked(string $roundId): bool
    {
        if (!$this->failed instanceof ListableReceiverInterface) {
            return false;
        }

        foreach ($this->failed->all() as $parked) {
            if ($this->judges($parked, $roundId)) {
                return true;
            }
        }

        return false;
    }

    private function judges(Envelope $parked, string $roundId): bool
    {
        $message = $parked->getMessage();

        return $message instanceof JudgeRound && $message->roundId === $roundId;
    }
}
