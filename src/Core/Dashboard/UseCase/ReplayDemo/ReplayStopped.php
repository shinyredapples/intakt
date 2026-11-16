<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use RuntimeException;

final class ReplayStopped extends RuntimeException
{
    public static function at(LedgerEventKind $kind, int $written, int $events, string $failure): self
    {
        return new self(sprintf('The replay stopped after %d of %d ledger events: the %s row was not appended (%s).', $written, $events, $kind->value, $failure));
    }
}
