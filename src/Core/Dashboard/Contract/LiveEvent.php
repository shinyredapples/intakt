<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

use InvalidArgumentException;

final readonly class LiveEvent
{
    public function __construct(
        public string $topic,
        public string $turboStream,
        public RefusalLogging $refusals = RefusalLogging::ByPublisher,
    ) {
        // Refused here, because an update without a topic would be accepted by no hub and reach no page.
        if ('' === trim($topic)) {
            throw new InvalidArgumentException('A live event needs a topic.');
        }
    }
}
