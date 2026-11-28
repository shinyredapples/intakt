<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Intakt\Core\Ledger\Contract\LedgerEventAppended;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

// Holds an event appended inside a transaction until the outermost commit, because its row is not stored before then and a rollback takes it back.
final class LedgerAnnouncements implements ResetInterface
{
    /** @var list<LedgerEventAppended> */
    private array $held = [];

    private bool $inTransaction = false;

    public function __construct(private readonly EventDispatcherInterface $events)
    {
    }

    public function announce(LedgerEventAppended $appended): void
    {
        if ($this->inTransaction) {
            $this->held[] = $appended;

            return;
        }

        $this->events->dispatch($appended);
    }

    public function transactionBegan(): void
    {
        $this->inTransaction = true;
    }

    public function committed(): void
    {
        $held = $this->held;
        $this->reset();

        foreach ($held as $appended) {
            $this->events->dispatch($appended);
        }
    }

    public function rolledBack(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->held = [];
        $this->inTransaction = false;
    }
}
