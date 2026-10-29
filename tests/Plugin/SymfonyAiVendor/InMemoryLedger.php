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

    public function __construct(private readonly bool $failing = false)
    {
    }

    #[Override]
    public function append(LedgerEvent $event): AppendResult
    {
        if ($this->failing) {
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
        return array_map(static fn (LedgerEvent $event): array => json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR), $this->events);
    }
}
