<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The Caddyfile as FrankenPHP reads it for the setup docs/reference/user-manual.md describes, because FrankenPHP takes its values from the process and the env files it is given, never from Symfony's .env loading.
#[Group('hub')]
#[CoversNothing]
final class CaddyfileTest extends TestCase
{
    private const string SECRET = 'a-test-key-that-is-at-least-thirty-two-bytes-long';

    private const string ISSUER = 'http://localhost:7790';

    private string $frankenphp;

    private string $directory;

    protected function setUp(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');

        if (null === $frankenphp) {
            self::markTestSkipped('The hub tests need the frankenphp binary, see docs/architecture/requirements.md.');
        }

        $this->frankenphp = $frankenphp;
        $this->directory = sys_get_temp_dir().'/intakt-caddyfile-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory);
        $filesystem->copy(dirname(__DIR__, 4).'/Caddyfile', $this->directory.'/Caddyfile');
        $filesystem->copy(dirname(__DIR__, 4).'/.env', $this->directory.'/.env');
        $filesystem->dumpFile($this->directory.'/.env.local', sprintf("MERCURE_JWT_SECRET=%s\nMERCURE_JWT_ISSUER=%s\n", self::SECRET, self::ISSUER));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            new Filesystem()->remove($this->directory);
        }
    }

    public function testTheHubTakesItsIssuerAndKeyFromEnvLocalWhenItIsTheFirstEnvFile(): void
    {
        $issuer = $this->issuerIn($this->adapted('--envfile', '.env.local', '--envfile', '.env'));

        self::assertSame(self::ISSUER, $issuer['identifier'] ?? null);
        self::assertSame(self::SECRET, $this->at($issuer, 'publisher', 'jwt', 'key'));
    }

    public function testTheHubGetsNoKeyWhenEnvComesFirst(): void
    {
        $issuer = $this->issuerIn($this->adapted('--envfile', '.env', '--envfile', '.env.local'));

        self::assertNotSame(self::SECRET, $this->at($issuer, 'publisher', 'jwt', 'key'));
    }

    public function testTheAdminApiListensOnLocalhostOnly(): void
    {
        self::assertSame(['localhost:2019'], (array) $this->at($this->adapted('--envfile', '.env.local', '--envfile', '.env'), 'admin', 'listen'));
    }

    public function testTheAppWorkerRunsTheFrontControllerInWorkerMode(): void
    {
        $worker = $this->worker('app');

        self::assertSame('public/index.php', $worker['file_name'] ?? null);
        self::assertSame('1', $this->at($worker, 'env', 'FRANKENPHP_WORKER'));
        self::assertArrayNotHasKey('match_path', $worker);
    }

    public function testTheMcpWorkerMatchesTheMcpRoutesWithItsOwnThreadLimits(): void
    {
        $worker = $this->worker('mcp');

        self::assertSame(['/mcp/*'], $worker['match_path'] ?? null);
        self::assertSame(8, $worker['max_threads'] ?? null);
        self::assertSame(2, $worker['num'] ?? null);
        self::assertSame('1', $this->at($worker, 'env', 'FRANKENPHP_WORKER'));
    }

    public function testTheWorkersUseDifferentFilesBecauseFrankenphpRefusesTwoOnOne(): void
    {
        self::assertNotSame($this->worker('app')['file_name'] ?? null, $this->worker('mcp')['file_name'] ?? null);
    }

    public function testRequestsWaitForAWorkerForAtMostThirtySeconds(): void
    {
        self::assertSame(30_000_000_000, $this->at($this->adapted('--envfile', '.env.local', '--envfile', '.env'), 'apps', 'frankenphp', 'max_wait_time'));
    }

    /**
     * @return array<mixed>
     */
    private function worker(string $name): array
    {
        $found = $this->workerIn($this->adapted('--envfile', '.env.local', '--envfile', '.env'), $name);
        self::assertNotNull($found, sprintf('No worker named %s.', $name));

        return $found;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>|null
     */
    // Searched, because the route nesting is Caddy's choice.
    private function workerIn(array $config, string $name): ?array
    {
        foreach (array_filter((array) ($config['workers'] ?? []), is_array(...)) as $worker) {
            if (($worker['name'] ?? null) === $name) {
                return $worker;
            }
        }

        foreach (array_filter($config, is_array(...)) as $nested) {
            $worker = $this->workerIn($nested, $name);

            if (null !== $worker) {
                return $worker;
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function adapted(string ...$envFiles): array
    {
        $process = new Process([$this->frankenphp, 'adapt', '--config', 'Caddyfile', '--adapter', 'caddyfile', ...$envFiles], $this->directory, ['MERCURE_JWT_SECRET' => false, 'MERCURE_JWT_ISSUER' => false, 'CADDY_ADMIN' => false]);
        $process->mustRun();
        $config = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    // Searched, because the route nesting is Caddy's choice and only the hub's first issuer is under test.
    private function issuerIn(array $config): array
    {
        $issuers = $config['issuers'] ?? null;

        if (is_array($issuers) && isset($issuers[0]) && is_array($issuers[0])) {
            return $issuers[0];
        }

        foreach (array_filter($config, is_array(...)) as $nested) {
            $issuer = $this->issuerIn($nested);

            if ([] !== $issuer) {
                return $issuer;
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $config
     */
    private function at(array $config, string ...$keys): mixed
    {
        $value = $config;

        foreach ($keys as $key) {
            if (!is_array($value)) {
                return null;
            }

            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
