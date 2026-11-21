<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\EndedOutageNotice;
use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Override;
use Throwable;

// Notices a test changes between two reads, because a live update is a page read again after a notice changed.
final class ChangingOwnerNotices implements OwnerNotices
{
    /** @var list<OutageNotice> */
    public array $running = [];

    /** @var list<EndedOutageNotice> */
    public array $ended = [];

    public ?Throwable $failure = null;

    /** @var list<DateTimeImmutable> */
    public private(set) array $daysAsked = [];

    #[Override]
    public function stillRunningOldestFirst(): array
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->running;
    }

    #[Override]
    public function endedOnTheDayOf(DateTimeImmutable $moment): array
    {
        $this->daysAsked[] = $moment;

        return $this->ended;
    }
}
