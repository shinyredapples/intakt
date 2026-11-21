<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One value for the page and its pushed update, so a pushed page never differs from one loaded after it, and the publisher compares what it read instead of a render.
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
