<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

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
     * @param list<string>         $topics  the page's own topics, listened to besides the shell's
     */
    public function render(Request $request, string $template, array $context, array $topics = []): string
    {
        $flashBag = $this->flashBagOf($request);
        $flashes = $flashBag->peekAll();

        try {
            return $this->twig->render($template, [...$context, 'live' => true, 'topics' => $topics]);
        } catch (Throwable $failure) {
            // Rendered again without the subscription, because a hub that cannot authorize a listener must leave the plain page standing; flashes put back, because the failed attempt read them.
            $flashBag->setAll($flashes);
            $this->logger->warning('A dashboard page was rendered without live updates.', ['template' => $template, 'exception' => $failure]);

            return $this->twig->render($template, [...$context, 'live' => false, 'topics' => []]);
        }
    }

    private function flashBagOf(Request $request): FlashBagInterface
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : new FlashBag();
    }
}
