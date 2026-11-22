<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Override;
use Symfony\Component\Uid\Uuid;

final class InMemoryLedger implements Ledger
{
    /** @var list<LedgerEvent> */
    private array $events = [];

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

    #[Override]
    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    /**
     * @param list<string> $texts
     */
    #[Override]
    public function readMentioning(array $texts): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    /**
     * @param list<LedgerEventKind> $kinds
     * @param list<string>          $texts
     */
    #[Override]
    public function readOfKindsMentioning(array $kinds, array $texts): RecordedEvents
    {
        return new RecordedEvents([]);
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @return list<LedgerEventKind>
     */
    public function kinds(): array
    {
        return array_map(static fn (LedgerEvent $event): LedgerEventKind => $event->kind, $this->events);
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
