<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

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
        if (!$this->labels instanceof StatusLabels) {
            return;
        }

        try {
            $this->labels->mirror($project, $change, $status);
            // Boundary: logged and dropped, because the next round or verdict sets the label again and a retry of the round must not wait on a label.
        } catch (Throwable $failure) {
            $this->logger->warning('The status label {status} of {change} of {project} was not set: {reason}', ['status' => $status->value, 'change' => $change->value, 'project' => $project, 'reason' => $failure->getMessage()]);
        }
    }
}
