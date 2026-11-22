<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Budget\Contract\OwnerNoticesOfTheDay;
use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Dashboard\Domain\HandledLine;
use Intakt\Core\Dashboard\Domain\MeanwhileLine;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// One reader for the page's items and panels, so the page shows them from one state.
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

    // One read of the notices for both lists, because a recovery committed between two reads would list one outage as waiting and as handled.
    public function read(): WaitingPage
    {
        $ofToday = $this->notices->waitingAndEndedOnTheDayOf($this->clock->now());

        return new WaitingPage($this->items($ofToday), $this->meanwhile(), $this->handledToday($ofToday));
    }

    /**
     * @return list<WaitingItem>
     */
    private function items(OwnerNoticesOfTheDay $ofToday): array
    {
        return array_map(
            fn (OutageNotice $notice): WaitingItem => WaitingItem::ofOutage($notice, $this->statusPages[$notice->account->provider->value] ?? null),
            $ofToday->waitingOldestFirst,
        );
    }

    /**
     * @return list<MeanwhileLine>
     */
    private function meanwhile(): array
    {
        return array_map(MeanwhileLine::ofReview(...), $this->pages->board()->runningReviews());
    }

    /**
     * @return list<HandledLine>
     */
    private function handledToday(OwnerNoticesOfTheDay $ofToday): array
    {
        return array_map(HandledLine::ofEndedOutage(...), $ofToday->endedNewestFirst);
    }
}
