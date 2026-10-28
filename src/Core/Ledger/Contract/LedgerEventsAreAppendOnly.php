<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use LogicException;

final class LedgerEventsAreAppendOnly extends LogicException
{
    public static function updated(): self
    {
        return new self('A stored ledger event cannot be updated: the ledger only appends.');
    }

    public static function deleted(): self
    {
        return new self('A stored ledger event cannot be deleted: the ledger only appends.');
    }
}
