<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// The Caddyfile as FrankenPHP reads it for the setup docs/user/README.md describes, because FrankenPHP takes its values from the process and the env files it is given, never from Symfony's .env loading.
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

        self::assertSame(self::ISSUER, $issuer['identifier']);
        self::assertSame(self::SECRET, $issuer['publisher']['jwt']['key']);
    }

    public function testTheHubGetsNoKeyWhenEnvComesFirst(): void
    {
        $issuer = $this->issuerIn($this->adapted('--envfile', '.env', '--envfile', '.env.local'));

        self::assertNotSame(self::SECRET, $issuer['publisher']['jwt']['key'] ?? null);
    }

    public function testTheAdminApiListensOnLocalhostOnly(): void
    {
        self::assertSame(['localhost:2019'], (array) $this->adapted('--envfile', '.env.local', '--envfile', '.env')['admin']['listen']);
    }

    /**
     * @return array<string, mixed>
     */
    private function adapted(string ...$envFiles): array
    {
        $process = new Process([$this->frankenphp, 'adapt', '--config', 'Caddyfile', '--adapter', 'caddyfile', ...$envFiles], $this->directory, ['MERCURE_JWT_SECRET' => false, 'MERCURE_JWT_ISSUER' => false, 'CADDY_ADMIN' => false]);
        $process->mustRun();

        /* @var array<string, mixed> */
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     */
    // Searched, because the route nesting is Caddy's choice and only the hub's first issuer is under test.
    private function issuerIn(array $config): array
    {
        if (isset($config['issuers'][0]) && is_array($config['issuers'][0])) {
            /* @var array<string, mixed> */
            return $config['issuers'][0];
        }

        foreach (array_filter($config, is_array(...)) as $nested) {
            $issuer = $this->issuerIn($nested);

            if ([] !== $issuer) {
                return $issuer;
            }
        }

        return [];
    }
}
