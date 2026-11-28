<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Throwable;
use Twig\Environment;

final readonly class LivePageRendering
{
    public function __construct(
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    // A page's own topics go in the context under `topics`, because the shell's one stream source listens to them besides its own.
    public function render(Request $request, string $template, array $context): string
    {
        $flashBag = $this->flashBagOf($request);
        $flashes = $flashBag->peekAll();

        try {
            return $this->twig->render($template, [...$context, 'live' => true, 'listener_grants' => self::listenerGrants()]);
            // Boundary: logs and renders again without the subscription, because a hub that cannot authorize a listener must leave the plain page standing.
        } catch (Throwable $failure) {
            // Flashes put back, because the failed attempt read them.
            $flashBag->setAll($flashes);
            $this->logger->warning('A dashboard page was rendered without live updates.', ['template' => $template, 'exception' => $failure]);

            return $this->twig->render($template, [...$context, 'live' => false, 'topics' => []]);
        }
    }

    /**
     * @return array{exact: list<string>, urlpattern: list<string>}
     */
    // The same grants on every page, because the subscriber cookie is shared by every tab: a list page that granted less would cut off a review tab that reconnects with it.
    public static function listenerGrants(): array
    {
        return ['exact' => [DashboardTopics::SHELL, DashboardTopics::HISTORY], 'urlpattern' => [MercureRoundUpdates::topicOf(':round')]];
    }

    private function flashBagOf(Request $request): FlashBagInterface
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : new FlashBag();
    }
}
