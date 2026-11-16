<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DashboardHost::class)]
final class DashboardHostTest extends TestCase
{
    // Shared with the tests of the listener and of the error page, so every user of the rule is tried with the same hosts.
    public const array FOREIGN_HOSTS = [
        'another name' => 'attacker.example:7780',
        'another name without a port' => 'attacker.example',
        'loopback without the port' => 'localhost',
        'loopback with another port' => '127.0.0.1:8080',
        'a name that starts like loopback' => 'localhost.attacker.example:7780',
    ];

    public const array LOOPBACK_HOSTS = [
        'localhost' => 'localhost:7780',
        'IPv4 loopback' => '127.0.0.1:7780',
        'IPv6 loopback' => '[::1]:7780',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignHosts(): iterable
    {
        foreach (self::FOREIGN_HOSTS as $name => $host) {
            yield $name => [$host];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackHosts(): iterable
    {
        foreach (self::LOOPBACK_HOSTS as $name => $host) {
            yield $name => [$host];
        }
    }

    #[DataProvider('foreignHosts')]
    public function testAHostThatIsNoLoopbackNameWithTheConfiguredPortDoesNotNameTheDashboard(string $host): void
    {
        self::assertFalse(new DashboardHost(7780)->isNamedBy(self::requestTo($host)));
    }

    #[DataProvider('loopbackHosts')]
    public function testALoopbackNameWithTheConfiguredPortNamesTheDashboard(string $host): void
    {
        self::assertTrue(new DashboardHost(7780)->isNamedBy(self::requestTo($host)));
    }

    public function testOnlyTheConfiguredPortNamesTheDashboard(): void
    {
        $dashboard = new DashboardHost(8080);

        self::assertTrue($dashboard->isNamedBy(self::requestTo('127.0.0.1:8080')));
        self::assertFalse($dashboard->isNamedBy(self::requestTo('127.0.0.1:7780')));
    }

    private static function requestTo(string $host): Request
    {
        return Request::create('/', server: ['HTTP_HOST' => $host]);
    }
}
