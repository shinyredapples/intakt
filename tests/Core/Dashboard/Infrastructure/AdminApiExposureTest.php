<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;
use Uri\Rfc3986\Uri;

// The shipped files as they are, because the admin API can change the whole server and must never be reached from outside the container; CaddyfileTest proves the address FrankenPHP adapts.
#[CoversNothing]
final class AdminApiExposureTest extends TestCase
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function testTheCaddyfileBindsTheAdminApiToLocalhostByDefault(): void
    {
        preg_match('/^\s*admin\s+\{\$CADDY_ADMIN:([^}\s]+)\}\s*$/m', $this->file('Caddyfile'), $match);

        self::assertSame('localhost:2019', $match[1] ?? null);
    }

    public function testTheCaddyfileDeclaresTheAdminApiOnce(): void
    {
        self::assertSame(1, preg_match_all('/^\s*admin\b/m', $this->file('Caddyfile')));
    }

    public function testNeitherTheImageNorComposeNorTheEnvFilesMoveTheAdminApi(): void
    {
        foreach (['Dockerfile', 'compose.yaml', '.env', '.env.dist', 'docker/entrypoint.sh', 'compose.dev.yaml'] as $file) {
            // A read of the variable (${CADDY_ADMIN:-...}) is fine, so only a name not opened by a brace counts as setting it.
            self::assertSame(0, preg_match('/(?<![{\w])CADDY_ADMIN\s*[=:]/', $this->file($file)), $file.' sets CADDY_ADMIN');
        }
    }

    public function testTheImageExposesOnlyTheDashboardPort(): void
    {
        preg_match_all('/^EXPOSE\s+(.+)$/m', $this->file('Dockerfile'), $matches);

        self::assertSame(['7781'], $matches[1]);
    }

    public function testComposePublishesOnlyTheDashboardPortOnLoopback(): void
    {
        /** @var array{services: array<string, array{ports?: list<string>}>} $compose */
        $compose = Yaml::parse($this->file('compose.yaml'));

        $published = array_merge(...array_values(array_map(static fn (array $service): array => $service['ports'] ?? [], $compose['services'])));

        self::assertSame(['127.0.0.1:7781:7781'], $published);
    }

    public function testTheDevelopmentOverrideAddsNoPublishedPort(): void
    {
        /** @var array{services: array<string, array<string, mixed>>} $override */
        $override = Yaml::parse($this->file('compose.dev.yaml'));

        self::assertArrayNotHasKey('ports', $override['services']['intakt']);
    }

    public function testTheAppReadsTheAdminApiOnLoopback(): void
    {
        $value = new Dotenv()->parse($this->file('.env'))['FRANKENPHP_ADMIN_URL'] ?? null;
        self::assertIsString($value);
        $url = new Uri($value);

        self::assertSame('http', $url->getScheme());
        self::assertContains($url->getHost(), self::LOOPBACK_HOSTS);
    }

    private function file(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
