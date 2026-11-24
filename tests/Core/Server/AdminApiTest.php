<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Infrastructure\AdminApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminApi::class)]
#[UsesClass(AdminApiNotOnLocalhost::class)]
final class AdminApiTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackUrls(): iterable
    {
        yield 'localhost' => ['http://localhost:2019'];
        yield 'localhost in capitals' => ['http://LOCALHOST:2019/'];
        yield 'IPv4 loopback' => ['http://127.0.0.1:2019'];
        yield 'IPv6 loopback' => ['http://[::1]:2019'];
    }

    #[DataProvider('loopbackUrls')]
    public function testAnAdminApiOnLoopbackIsRead(string $baseUrl): void
    {
        $answer = new RecordedAdminApi()->adminApi($baseUrl)->get('/mercure/health/ready');

        self::assertSame(['status' => 200, 'body' => RecordedAdminApi::fixture('hub_ready.json')], $answer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsOffLocalhost(): iterable
    {
        yield 'another host' => ['http://admin.example:2019'];
        yield 'a documentation address' => ['http://192.0.2.10:2019'];
        yield 'a documentation IPv6 address' => ['http://[2001:db8::1]:2019'];
        yield 'a host that starts with localhost' => ['http://localhost.example:2019'];
        yield 'https' => ['https://localhost:2019'];
        yield 'credentials in the URL' => ['http://user@localhost:2019'];
        yield 'no scheme' => ['localhost:2019'];
    }

    #[DataProvider('urlsOffLocalhost')]
    public function testAnAdminApiOffLocalhostIsRefusedBeforeAnyRequest(string $baseUrl): void
    {
        $recorded = new RecordedAdminApi();

        try {
            $recorded->adminApi($baseUrl)->get('/metrics');
            self::fail('The admin API off localhost was read.');
        } catch (AdminApiNotOnLocalhost) {
            self::assertSame([], $recorded->requestOptions);
        }
    }

    public function testATextThatIsNotAUrlIsRefused(): void
    {
        $this->expectExceptionObject(AdminApiNotOnLocalhost::becauseItIsNotAUrl());

        new RecordedAdminApi()->adminApi('http://local host')->get('/metrics');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsWithAQueryOrFragment(): iterable
    {
        yield 'a query' => ['http://localhost:2019?password=synthetic-query-secret'];
        yield 'a query after a path' => ['http://localhost:2019/?password=synthetic-query-secret'];
        yield 'a fragment' => ['http://localhost:2019#synthetic-query-secret'];
        yield 'a query and a fragment' => ['http://localhost:2019?password=synthetic-query-secret#more'];
    }

    #[DataProvider('urlsWithAQueryOrFragment')]
    public function testTheQueryAndFragmentOfTheConfiguredUrlAreNeverSent(string $baseUrl): void
    {
        $recorded = new RecordedAdminApi();

        $recorded->adminApi($baseUrl)->get('/metrics');

        self::assertSame(['http://localhost:2019/metrics'], $recorded->requestedUrls);
    }

    public function testARedirectIsNeverFollowed(): void
    {
        $recorded = new RecordedAdminApi();

        $recorded->adminApi()->get('/metrics');

        self::assertSame(0, $recorded->requestOptions[0]['max_redirects'] ?? null);
    }
}
