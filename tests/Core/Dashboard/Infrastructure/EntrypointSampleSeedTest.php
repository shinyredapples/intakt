<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// Runs the image's entrypoint with stand-ins for php and frankenphp, because what is proven here is the order of the start-up steps, not the steps themselves.
#[CoversNothing]
final class EntrypointSampleSeedTest extends TestCase
{
    // An upper bound, not a pause: the wait ends at the first consumer, and the parallel test workers can hold the stubbed steps back for more than ten seconds.
    private const int SECONDS_TO_WAIT = 60;

    private const string SECRET = 'entrypoint-sample-seed-test-secret-value';

    private string $directory;

    private ?Process $entrypoint = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-entrypoint-seed-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->entrypoint?->stop(15);
        new Filesystem()->remove($this->directory);
    }

    public function testTheDemoBuildsTheContainerAgainBeforeAndAfterTheSeed(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => '/data/intakt.yaml']);

        $this->waitForTheServer();

        self::assertSame([
            'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
            'bin/console cache:clear --no-warmup --no-interaction',
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:sample:seed /data/sample /data/intakt.yaml',
            'bin/console cache:clear --no-warmup --no-interaction',
            'bin/console cache:warmup --no-interaction',
        ], $this->consoleCallsBeforeTheConsumers());
    }

    public function testWithoutASampleRepositoryNothingIsSeeded(): void
    {
        $this->startEntrypoint([]);

        $this->waitForTheServer();

        self::assertSame(['bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'], $this->consoleCallsBeforeTheConsumers());
    }

    public function testAFailedSeedStopsTheEntrypointBeforeTheServerStarts(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => '/data/intakt.yaml', 'SEED_STATUS' => '1']);

        $this->entrypoint?->wait();

        self::assertSame(1, $this->entrypoint?->getExitCode());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    public function testASampleRepositoryWithoutAProjectFileIsRefusedByName(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('INTAKT_CONFIG', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    /**
     * @param array<string, string> $environment
     */
    private function startEntrypoint(array $environment): void
    {
        $this->entrypoint = new Process(['docker/entrypoint.sh'], dirname(__DIR__, 4), [
            'PATH' => $this->stubPrograms().':'.getenv('PATH'),
            'CALLS' => $this->callsFile(),
            'APP_SECRET' => self::SECRET,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => 'http://localhost',
            'MESSENGER_CONSUMERS' => '1',
            'WORKER_MEMORY_CEILING_MB' => '256',
            'INTAKT_SAMPLE_REPOSITORY' => false,
            'INTAKT_CONFIG' => false,
            ...$environment,
        ]);
        $this->entrypoint->setTimeout(self::SECONDS_TO_WAIT * 2);
        $this->entrypoint->start();
    }

    // Stand-ins that note each call; the console steps end at once (the seed with SEED_STATUS), the server and the consumers wait to be stopped, without the test's pipes, so a sleep the entrypoint leaves behind cannot hold the test.
    private function stubPrograms(): string
    {
        $programs = $this->directory.'/stubs';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($programs.'/php', "#!/bin/bash\necho \"php \$*\" >> \"\$CALLS\"\ncase \"\$*\" in *messenger:consume*) exec sleep 60 </dev/null >/dev/null 2>&1;; *intakt:sample:seed*) exit \"\${SEED_STATUS:-0}\";; esac\nexit 0\n");
        $filesystem->dumpFile($programs.'/frankenphp', "#!/bin/bash\necho \"frankenphp \$*\" >> \"\$CALLS\"\ncase \"\$*\" in *validate*) exit 0;; esac\nexec sleep 60 </dev/null >/dev/null 2>&1\n");
        $filesystem->chmod([$programs.'/php', $programs.'/frankenphp'], 0755);

        return $programs;
    }

    private function waitForTheServer(): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && !str_contains($this->calls(), 'messenger:consume scheduler_watcher')) {
            usleep(50_000);
        }

        self::assertStringContainsString('frankenphp run', $this->calls(), (string) $this->entrypoint?->getErrorOutput());
    }

    /**
     * @return list<string>
     */
    private function consoleCallsBeforeTheConsumers(): array
    {
        $calls = [];
        foreach (explode("\n", $this->calls()) as $call) {
            if (str_contains($call, 'messenger:consume')) {
                break;
            }

            if (str_starts_with($call, 'php ')) {
                $calls[] = substr($call, 4);
            }
        }

        return $calls;
    }

    private function calls(): string
    {
        $calls = @file_get_contents($this->callsFile());

        return is_string($calls) ? $calls : '';
    }

    private function callsFile(): string
    {
        return $this->directory.'/calls';
    }
}
