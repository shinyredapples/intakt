<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

// Read from the failure transport, because a judging that failed for good leaves no mark on the round or in its ledger: its parked message is the only trace.
final readonly class FailedJudgings
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private ReceiverInterface $failed,
    ) {
    }

    // A transport that cannot be listed (the in-memory one of the test environment) shows no failure, because reading it with get() would hand its messages out.
    public function hasFailed(string $roundId): bool
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
