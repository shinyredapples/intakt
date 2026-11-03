<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\RoundUpdates;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

// Apart from LiveProgress, which is never read back, because this one decides something: the dashboard runs in another process and can only leave a request in the ledger, so the reviewer's own process cancels its execution when it finds one.
final readonly class ReviewerStopping
{
    public function __construct(
        private Ledger $ledger,
        private RoundUpdates $updates,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    // The request is read from the ledger, never from model output, so only a person's press on the dashboard can stop a reviewer; the cancel lands at the next progress update.
    public function watching(ExecutionInterface $execution, ReviewerConversation $conversation): ExecutionInterface
    {
        $round = $conversation->round;
        $reviewer = $conversation->reviewer;

        if (!$round instanceof Uuid || !$reviewer instanceof Uuid) {
            return $execution;
        }

        $name = $reviewer->toRfc4122();
        $pace = new StopPace($this->clock);

        return $execution->onProgress(function () use ($execution, $round, $name, $pace): void {
            if ($pace->due() && $this->pending($round, $name)) {
                $this->stop($execution, $round, $name);
            }
        });
    }

    // Pending until a later stop answers it, by place in the ledger rather than by time, so a press while the reviewer waits for a retry or before its call begins still stops the run, and an answered press never stops a later one.
    private function pending(Uuid $round, string $reviewer): bool
    {
        $pending = false;

        foreach ($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested, LedgerEventKind::ReviewerStopped) as $recorded) {
            if ($this->names($recorded, $round, $reviewer)) {
                $pending = LedgerEventKind::ReviewerStopRequested === $recorded->event->kind;
            }
        }

        return $pending;
    }

    private function names(RecordedEvent $recorded, Uuid $round, string $reviewer): bool
    {
        $payload = json_decode($recorded->event->payload, true);

        return is_array($payload) && ($payload['round'] ?? null) === $round->toRfc4122() && ($payload['reviewer'] ?? null) === $reviewer;
    }

    private function stop(ExecutionInterface $execution, Uuid $round, string $reviewer): void
    {
        $this->record($round, $reviewer);
        $execution->cancel();
        $this->updates->reviewerStopped($round, $reviewer);
    }

    // Logged, not thrown, because the person's stop stands without its record.
    private function record(Uuid $round, string $reviewer): void
    {
        $payload = json_encode(['round' => $round->toRfc4122(), 'reviewer' => $reviewer], JSON_THROW_ON_ERROR);
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::ReviewerStopped, $payload));

        if (!$appended->succeeded()) {
            $this->logger->error('A reviewer stop was not recorded.', ['stop' => $payload, 'reason' => $appended->failure]);
        }
    }
}
