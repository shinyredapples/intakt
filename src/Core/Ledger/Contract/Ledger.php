<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

interface Ledger
{
    #[\NoDiscard('a failed append is only reported through the result')]
    public function append(LedgerEvent $event): AppendResult;

    /**
     * @return list<RecordedEvent> in the order they were appended
     */
    public function readInOrder(): array;
}
