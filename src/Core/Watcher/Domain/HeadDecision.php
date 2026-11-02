<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class HeadDecision
{
    public function __construct(
        public OpenChange $change,
        public HeadAdmission $admission,
        // Only a pending head has one, because nothing else is read again; it holds no lock and no lane.
        public ?DateTimeImmutable $readAgainAt = null,
    ) {
    }

    public function startsRound(): bool
    {
        return HeadAdmission::Green === $this->admission;
    }
}
