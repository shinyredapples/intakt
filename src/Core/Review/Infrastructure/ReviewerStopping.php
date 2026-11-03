<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use DateTimeImmutable;
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
    public function watching(ExecutionInterface $execution, ReviewerConversation $reviewer): ExecutionInterface
    {
        $round = $reviewer->round;

        if (!$round instanceof Uuid) {
            return $execution;
        }

        $name = $reviewer->id->toRfc4122();
        // A second back, because the ledger keeps whole seconds and a request pressed in the second this run began would otherwise look older; a request left from an earlier run is older than that.
        $since = $this->clock->now()->modify('-1 second');
        $pace = new StopPace($this->clock);

        return $execution->onProgress(function () use ($execution, $round, $name, $since, $pace): void {
            if ($pace->due() && $this->requested($round, $name, $since)) {
                $this->stop($execution, $round, $name);
            }
        });
    }

    private function requested(Uuid $round, string $reviewer, DateTimeImmutable $since): bool
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested) as $recorded) {
            if ($recorded->recordedAt >= $since && $this->names($recorded, $round, $reviewer)) {
                return true;
            }
        }

        return false;
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
