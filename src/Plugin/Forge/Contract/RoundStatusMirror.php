<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

// A label is a mirror of the round and never part of it, so a forge without labels or a write that fails costs the round nothing.
final readonly class RoundStatusMirror
{
    public function __construct(
        private LoggerInterface $logger,
        private ?StatusLabels $labels = null,
    ) {
    }

    public function show(string $project, ChangeId $change, RoundStatusLabel $status): void
    {
        $this->tolerating($project, $change, static fn (StatusLabels $labels) => $labels->mirror($project, $change, $status));
    }

    public function restore(string $project, ChangeId $change): void
    {
        $this->tolerating($project, $change, static fn (StatusLabels $labels) => $labels->restore($project, $change));
    }

    /**
     * @param Closure(StatusLabels): void $write
     */
    private function tolerating(string $project, ChangeId $change, Closure $write): void
    {
        if (!$this->labels instanceof StatusLabels) {
            return;
        }

        try {
            $write($this->labels);
            // Boundary: logged and dropped, because the next round step sets the label again and a retry of the round must not wait on a label.
        } catch (Throwable $failure) {
            $this->logger->warning('The status label of {change} of {project} was not set: {reason}', ['change' => $change->value, 'project' => $project, 'reason' => $failure->getMessage()]);
        }
    }
}
