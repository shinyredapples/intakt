<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;

// Covers nothing because the client is a line of the base template, which FrankenPHP's hot_reload in docker/dev/Caddyfile turns on.
#[CoversNothing]
final class HotReloadClientTest extends DashboardPageTestCase
{
    use DownloadedScriptStandIns;

    public function testAPageServedUnderHotReloadListensOnTheHubUrlFrankenphpGivesAndReloads(): void
    {
        $page = $this->browser->request('GET', '/components', server: ['FRANKENPHP_HOT_RELOAD' => 'http://localhost:7781/.well-known/mercure?topic=hot-reload']);

        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost:7781/.well-known/mercure?topic=hot-reload', $page->filter('head meta[name="frankenphp-hot-reload:url"]')->attr('content'));
        self::assertStringContainsString('new EventSource(', $page->filter('head meta[name="frankenphp-hot-reload:url"] + script')->text());
        self::assertStringContainsString('location.reload()', $page->filter('head meta[name="frankenphp-hot-reload:url"] + script')->text());
    }

    public function testAPageServedWithoutHotReloadLoadsNoReloadClient(): void
    {
        $page = $this->browser->request('GET', '/components');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('meta[name="frankenphp-hot-reload:url"]'));
        self::assertStringNotContainsString('EventSource', (string) $this->browser->getResponse()->getContent());
    }
}
