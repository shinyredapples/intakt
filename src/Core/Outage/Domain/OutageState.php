<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Outage\Contract\Reachability;

final readonly class OutageState
{
    public DateTimeImmutable $since;

    public function __construct(
        public Reachability $reachability,
        DateTimeImmutable $since,
    ) {
        $this->since = $since->setTimezone(new DateTimeZone('UTC'));
    }
}
