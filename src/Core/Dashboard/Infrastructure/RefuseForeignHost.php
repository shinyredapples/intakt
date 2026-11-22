<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

// A page answering any Host would hand the ledger to a site that rebinds its name to loopback (design: Dashboard, Who can act).
#[AsEventListener]
final readonly class RefuseForeignHost
{
    // Plain HTML without Twig or assets, because the refusal must answer where neither the templates nor the asset paths are there (a copy bin/instance serves); colors are the light and dark tokens of assets/styles/tokens.css.
    private const string PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Open the dashboard on localhost - Intakt</title>
        <style>
        :root { color-scheme: light dark; --page: #F7FAF9; --ink: #0A2B23; --tint: #EEF5F2; }
        @media (prefers-color-scheme: dark) { :root { --page: #121816; --ink: #E6ECEA; --tint: #1E2826; } }
        body { margin: 0; padding: 32px; background: var(--page); color: var(--ink); font: 17px/1.5 system-ui, sans-serif; }
        main { max-width: 640px; }
        pre { padding: 12px 16px; border-radius: 12px; background: var(--tint); overflow-x: auto; }
        </style>
        </head>
        <body>
        <main>
        <h1>Open the dashboard on localhost</h1>
        <p class="lead">Intakt answers only <code>http://localhost:%1$d</code>, on the machine it runs on. The dashboard has no login, so any other address could let a web page you visit act for you.</p>
        <p>From another device, open an SSH tunnel to that machine, then open <code>http://localhost:%1$d</code> on this device:</p>
        <pre><code>ssh -L %1$d:localhost:%1$d you@your-server</code></pre>
        </main>
        </body>
        </html>
        HTML;

    public function __construct(
        private DashboardHost $dashboard,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$this->isDashboardPage($request) || $this->dashboard->isNamedBy($request)) {
            return;
        }

        // Says how to reach the dashboard instead, because a person who typed the machine's address only learns here that localhost is the one that works.
        $event->setResponse(new Response(sprintf(self::PAGE, $this->dashboard->port()), Response::HTTP_BAD_REQUEST));
    }

    private function isDashboardPage(Request $request): bool
    {
        return str_starts_with($request->attributes->getString('_route'), DashboardRoutes::PREFIX);
    }
}
