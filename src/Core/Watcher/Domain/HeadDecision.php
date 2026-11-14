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
        // Only a head sent back by the boot gate has one, because it carries the reason shown for it.
        public ?FailedBoot $failedBoot = null,
    ) {
    }

    public function sentBackFor(FailedBoot $boot): self
    {
        return clone ($this, ['admission' => HeadAdmission::FailedBoot, 'failedBoot' => $boot]);
    }

    public function startsRound(): bool
    {
        return HeadAdmission::Green === $this->admission;
    }
}
