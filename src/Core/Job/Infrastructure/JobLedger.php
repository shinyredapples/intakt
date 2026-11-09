<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobOutcome;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use RuntimeException;

final readonly class JobLedger
{
    public function __construct(
        private Ledger $ledger,
    ) {
    }

    // A failed append throws, because the end cause is the record of why a job stopped and must not be lost silently.
    public function ended(JobOutcome $outcome): void
    {
        $payload = json_encode([
            'job_id' => $outcome->jobId,
            'cause' => $outcome->cause->value,
            'exit_code' => $outcome->exitCode,
            'reduced' => $outcome->startCheck->reduced(),
            'egress_refusals' => count($outcome->egressRefusals),
        ], JSON_THROW_ON_ERROR);
        $result = $this->ledger->append(new LedgerEvent(LedgerEventKind::JobEnded, $payload));

        if (!$result->succeeded()) {
            throw new RuntimeException(sprintf('The end of job %s was not recorded: %s', $outcome->jobId, $result->failure));
        }
    }
}
