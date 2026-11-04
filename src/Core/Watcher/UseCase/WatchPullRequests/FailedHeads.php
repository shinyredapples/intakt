<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

final readonly class FailedHeads
{
    private const int FIRST_WAIT_SECONDS = 60;

    // Capped, because until the maintainer agent takes a handed-over head, a fault that clears (a commit not fetched yet, a CI endpoint down) must still let the head be reviewed within half an hour.
    private const int LONGEST_WAIT_SECONDS = 1800;

    // Four in a row, as for a failing bench, because a passing fault clears within those doubling waits and one that does not needs someone to look.
    private const int HAND_OVER_AFTER = 4;

    public function __construct(
        private PendingHeads $pending,
        private WatcherLedger $ledger,
        private ClockInterface $clock,
    ) {
    }

    public function backOff(string $project, OpenChange $head, Throwable $failure): void
    {
        $failures = $this->pending->failuresOf($project, $head) + 1;
        $headFailure = new HeadFailure($project, $head, $this->causeOf($failure));
        $retryAt = $this->retryAt($failures);

        // Recorded before the row is changed, because a failure the ledger could not take must leave the head due, so the tick fails and the next one tries again.
        $this->record($headFailure, $failures, $retryAt);
        $this->pending->backOff($headFailure, $failures, $retryAt);
    }

    // Scrubbed first, because a message may carry bytes of a path or a tool's output that are not UTF-8, and the ledger line must still be written.
    private function causeOf(Throwable $failure): string
    {
        return $failure::class.': '.new Redaction()->redact(mb_scrub($failure->getMessage(), 'UTF-8'));
    }

    private function retryAt(int $failures): DateTimeImmutable
    {
        $wait = min(self::FIRST_WAIT_SECONDS * 2 ** ($failures - 1), self::LONGEST_WAIT_SECONDS);

        return $this->clock->now()->modify(sprintf('+%d seconds', $wait));
    }

    // Handed over once, at the failure that reaches the limit, so the maintainer agent gets one line per head while later tries are recorded as failures.
    private function record(HeadFailure $failure, int $failures, DateTimeImmutable $retryAt): void
    {
        if (self::HAND_OVER_AFTER === $failures) {
            $this->ledger->headHandedOver($failure, $failures, $retryAt);

            return;
        }

        $this->ledger->headFailed($failure, $failures, $retryAt);
    }
}
