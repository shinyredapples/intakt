<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Closure;
use DateTimeImmutable;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Watcher\Domain\HeadFailure;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

// One head's failure backs off that head alone, because a head that fails on every try once held back every other head of its lane for 40 minutes.
final readonly class HeadBackOff
{
    private const int FIRST_WAIT_SECONDS = 60;

    // Four in a row, as for a failing bench, because a passing fault clears within those doubling waits and one that does not needs someone to look.
    private const int HAND_OVER_AFTER = 4;

    public function __construct(
        private PendingHeads $pending,
        private WatcherLedger $ledger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    // Boundary: catches every failure of one head's step, because any of them must back off only that head and let the tick go on to the next.
    /**
     * @template T
     *
     * @param Closure(): T $step
     *
     * @return T|null
     */
    public function tryAlone(string $project, OpenChange $head, Closure $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $failure) {
            $this->backOff($project, $head, $failure);

            return null;
        }
    }

    private function backOff(string $project, OpenChange $head, Throwable $failure): void
    {
        $failures = $this->pending->failuresOf($project, $head) + 1;
        $headFailure = new HeadFailure($project, $head, $failure::class.': '.new Redaction()->redact($failure->getMessage()));
        $this->logger->error('A head failed; it backs off alone.', ['project' => $project, 'change' => $head->id->value, 'head' => $head->head->hash, 'failures' => $failures, 'exception' => $failure]);

        if ($failures >= self::HAND_OVER_AFTER) {
            $this->handOver($headFailure, $failures);

            return;
        }

        $retryAt = $this->retryAt($failures);
        // Recorded before the row is changed, because a failure the ledger could not take must leave the head due, so the tick fails and the next one tries again.
        $this->ledger->headFailed($headFailure, $failures, $retryAt);
        $this->pending->backOff($headFailure, $failures, $retryAt);
    }

    // Leaves the queue until its pull request changes, because the maintainer agent takes it from the ledger line and another try would fail the same way.
    private function handOver(HeadFailure $failure, int $failures): void
    {
        $this->ledger->headHandedOver($failure, $failures);
        $this->pending->forget($failure->project, $failure->head);
    }

    private function retryAt(int $failures): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', self::FIRST_WAIT_SECONDS * 2 ** ($failures - 1)));
    }
}
