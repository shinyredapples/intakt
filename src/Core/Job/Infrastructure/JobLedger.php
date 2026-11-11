<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

final readonly class JobLedger
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function jobEnded(JobId $job, JobEnd $end): void
    {
        $this->append(LedgerEventKind::JobEnded, ['job' => $job->value, 'cause' => $end->cause->value, 'exit_code' => $end->exitCode]);
    }

    public function orphanRemoved(JobContainer $container): void
    {
        $this->append(LedgerEventKind::JobContainerRemoved, ['job' => $container->job->value, 'container' => $container->id]);
    }

    /**
     * @param array<string, int|string> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw JobEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
