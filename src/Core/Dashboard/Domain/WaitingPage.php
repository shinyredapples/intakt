<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One value for the items and both panels, because they must come from one read of the notices.
final readonly class WaitingPage
{
    /**
     * @param list<WaitingItem>   $items
     * @param list<MeanwhileLine> $meanwhile
     * @param list<HandledLine>   $handled
     */
    public function __construct(
        public array $items,
        public array $meanwhile,
        public array $handled,
    ) {
    }
}
