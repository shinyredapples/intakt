<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

// Through the hub's endpoint, not mercure_publish(), because consumers and the console have no FrankenPHP function; one path serves every process.
#[AsAlias(LiveUpdates::class)]
final readonly class MercureLiveUpdates implements LiveUpdates
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function publish(LiveEvent $event): bool
    {
        try {
            // Private, because the dashboard shows private projects; only a subscriber the dashboard authorizes may read it.
            $this->hub->publish(new Update($event->topic, $event->turboStream, true));

            return true;
            // Boundary: logs a failed publication and swallows it, because a hub that is down must never fail the request or the work that published.
        } catch (Throwable $failure) {
            $this->logger->warning('A live dashboard update was not published.', ['topic' => $event->topic, 'exception' => $failure]);

            return false;
        }
    }
}
