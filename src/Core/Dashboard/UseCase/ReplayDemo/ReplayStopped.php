<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use RuntimeException;

final class ReplayStopped extends RuntimeException
{
    public static function at(Recording $recording, int $written, string $failure): self
    {
        return new self(sprintf('The replay stopped after %d of %d ledger events: the %s row was not appended (%s).', $written, count($recording->events), $recording->events[$written]->kind->value, $failure));
    }
}
