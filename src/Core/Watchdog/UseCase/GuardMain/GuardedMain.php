<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\GuardMain;

use Intakt\Core\Watchdog\Contract\OwnerCard;

final readonly class GuardedMain
{
    /**
     * @param list<OwnerCard> $standingCards
     * @param list<string>    $noticedOwnerPushes
     * @param list<string>    $peerMerges
     */
    public function __construct(
        public array $standingCards,
        // Only the notices this run gave, because a notice another run or clone gave already is not given again.
        public array $noticedOwnerPushes,
        public array $peerMerges,
    ) {
    }
}
