<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use RuntimeException;

// It names the removal only once a row is written, because those rows keep their round in the ledger and a second replay is refused while they stay, while the removal would also delete every earlier replay.
final class ReplayStopped extends RuntimeException
{
    public static function at(Recording $recording, int $written, string $failure): self
    {
        $stop = sprintf('The replay stopped after %d of %d ledger events: the %s row was not appended (%s).', $written, count($recording->events), $recording->events[$written]->kind->value, $failure);

        if (0 === $written) {
            return new self($stop.' Nothing was written, so replay this recording again.');
        }

        return new self($stop.' The events it wrote stay in the ledger: remove them with intakt:demo:remove, then run intakt:demo:create, before replaying this recording.');
    }
}
