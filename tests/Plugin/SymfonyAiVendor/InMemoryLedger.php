<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;
use Symfony\Component\Uid\Uuid;

final class InMemoryLedger implements Ledger
{
    /** @var list<LedgerEvent> */
    public array $events = [];

    public function __construct(private readonly int $accepted = PHP_INT_MAX)
    {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if (count($this->events) >= $this->accepted) {
            return AppendResult::failed('the ledger is down');
        }

        $this->events[] = $event;

        return AppendResult::appended(Uuid::v7());
    }

    #[Override]
    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        $payloads = [];
        foreach ($this->events as $event) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
            $payloads[] = $payload;
        }

        return $payloads;
    }
}
