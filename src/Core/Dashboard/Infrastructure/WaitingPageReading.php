<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Dashboard\Domain\HandledLine;
use Intakt\Core\Dashboard\Domain\MeanwhileLine;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// One reader for the page and its live update, so both show the same items and panels.
final readonly class WaitingPageReading
{
    /**
     * @param array<string, string> $statusPages
     */
    public function __construct(
        private OwnerNotices $notices,
        private LedgerReviewPages $pages,
        private ClockInterface $clock,
        #[Autowire(param: DashboardServices::STATUS_PAGES)]
        private array $statusPages,
    ) {
    }

    public function read(): WaitingPage
    {
        return new WaitingPage($this->items(), $this->meanwhile(), $this->handledToday());
    }

    /**
     * @return list<WaitingItem>
     */
    private function items(): array
    {
        return array_map(
            fn (OutageNotice $notice): WaitingItem => WaitingItem::ofOutage($notice, $this->statusPages[$notice->account->provider->value] ?? null),
            $this->notices->stillRunningOldestFirst(),
        );
    }

    /**
     * @return list<MeanwhileLine>
     */
    private function meanwhile(): array
    {
        return array_map(MeanwhileLine::ofReview(...), $this->pages->board()->runningReviews());
    }

    // Today is the UTC day, because every moment Intakt stores is UTC.
    /**
     * @return list<HandledLine>
     */
    private function handledToday(): array
    {
        return array_map(HandledLine::ofEndedOutage(...), $this->notices->endedOnTheDayOf($this->clock->now()));
    }
}
