<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\EndedOutageNotice;

// A record of today that no longer waits, for the Waiting on you page's "You handled today"; until the owner notice records of plan T67 (#349) exist, an outage ends when its readings come back, so that is what it says.
final readonly class HandledLine
{
    public function __construct(
        public string $text,
        public DateTimeImmutable $at,
    ) {
    }

    public static function ofEndedOutage(EndedOutageNotice $notice): self
    {
        return new self('Readings of account '.$notice->account->key().' came back', $notice->endedAt);
    }
}
