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
    // Generous because the parallel test workers can hold the stubbed steps back for more than ten seconds; the wait still ends at the first consumer.
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
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:sample:seed /data/sample /data/intakt.yaml',
            'bin/console cache:warmup --no-interaction',
        ], $this->consoleCallsBeforeTheConsumers());
    }

    public function testTheContainerOfTheImageIsRemovedBeforeEachWarmup(): void
    {
        $stale = $this->directory.'/var/cache/test/stale-container';
        new Filesystem()->dumpFile($stale, 'old');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => '/data/intakt.yaml']);

        $this->waitForTheServer();

        self::assertFileDoesNotExist($stale);
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
        // Run inside the test's own directory with APP_ENV=test, so the entrypoint's removal of the cache directory never touches this checkout.
        $this->entrypoint = new Process([dirname(__DIR__, 4).'/docker/entrypoint.sh'], $this->directory, [
            'APP_ENV' => 'test',
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
        // The server notes its run only after the scheduler watcher (bounded): the entrypoint starts them in parallel, so the test forces the order it must survive.
        $filesystem->dumpFile($programs.'/frankenphp', "#!/bin/bash\ncase \"\$*\" in *validate*) ;; *) for _ in {1..600}; do grep -q scheduler_watcher \"\$CALLS\" && break; sleep 0.1; done;; esac\necho \"frankenphp \$*\" >> \"\$CALLS\"\ncase \"\$*\" in *validate*) exit 0;; esac\nexec sleep 60 </dev/null >/dev/null 2>&1\n");
        $filesystem->chmod([$programs.'/php', $programs.'/frankenphp'], 0755);

        return $programs;
    }

    private function waitForTheServer(): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && (!str_contains($this->calls(), 'frankenphp run') || !str_contains($this->calls(), 'messenger:consume scheduler_watcher'))) {
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
