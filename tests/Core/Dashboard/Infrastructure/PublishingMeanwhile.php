<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Closure;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Override;

// Runs its step once right after the first publish, while the publisher still holds its lock, so a test can let another worker change the board in the middle.
final class PublishingMeanwhile implements LiveUpdates
{
    /**
     * @param (Closure(): void)|null $meanwhile
     */
    public function __construct(
        private readonly LiveUpdates $live,
        private ?Closure $meanwhile,
    ) {
    }

    #[Override]
    public function publish(LiveEvent $event): bool
    {
        $meanwhile = $this->meanwhile;
        $this->meanwhile = null;
        $delivered = $this->live->publish($event);

        if ($meanwhile instanceof Closure) {
            $meanwhile();
        }

        return $delivered;
    }
}
