<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class OutageWatch
{
    // A few hours, because a gap between two calls is normal and only a reading that stays away this long needs the owner.
    public const int OUTAGE_SECONDS = 3 * 3600;

    public function __construct(
        private MeasuredUse $measured,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    // An account that was never read has no outage, because a newly used account waits for its first reading anyway.
    public function watch(Account $account): void
    {
        $newest = $this->newestUseOf($account);
        if ($newest instanceof WindowUse && $newest->ageInSeconds >= self::OUTAGE_SECONDS) {
            $this->events->dispatch(new UsageReadingsStayedAway($account, $newest->takenAt, $this->clock->now()));
        }
    }

    // By the stored moment, not by age, because each window's age is sampled at its own clock reading and a pause between the lookups would make the older reading look newer.
    private function newestUseOf(Account $account): ?WindowUse
    {
        $uses = array_filter(array_map(fn (UsageWindow $window): ?WindowUse => $this->measured->of($account, $window), UsageWindow::cases()));
        usort($uses, static fn (WindowUse $one, WindowUse $other): int => $one->takenAt <=> $other->takenAt);

        return array_last($uses);
    }
}
