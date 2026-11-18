<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use RuntimeException;

// It names the removal, because the rows already written keep their round in the ledger and a second replay of the recording is refused while they stay.
final class ReplayStopped extends RuntimeException
{
    public static function at(Recording $recording, int $written, string $failure): self
    {
        return new self(sprintf('The replay stopped after %d of %d ledger events: the %s row was not appended (%s). The events it wrote stay in the ledger: remove them with intakt:demo:remove, then run intakt:demo:create, before replaying this recording.', $written, count($recording->events), $recording->events[$written]->kind->value, $failure));
    }
}
