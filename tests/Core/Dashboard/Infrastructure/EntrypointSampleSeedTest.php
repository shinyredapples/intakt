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
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->waitForTheServer();

        self::assertSame([
            'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:sample:seed /data/sample '.$this->directory.'/intakt.yaml',
            'bin/console cache:warmup --no-interaction',
        ], $this->consoleCallsBeforeTheConsumers());
    }

    public function testEveryOldContainerIsRemovedBeforeItsWarmup(): void
    {
        new Filesystem()->dumpFile($this->directory.'/var/cache/test/image-container', 'old');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/var/cache/test', 'the image container or the one the seed left survived');
    }

    public function testTheDemoMakesOneOwnerTokenAndKeepsItOnTheDataVolume(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();

        $kept = trim(new Filesystem()->readFile($this->directory.'/mcp-owner-token'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $kept);
        self::assertSame($kept.' mcp_tool_write', trim($this->seen()));
    }

    public function testATokenTheOwnerSetWinsAndIsNotWrittenToTheVolume(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'MCP_OWNER_TOKEN' => 'chosen-by-the-owner', 'MCP_OWNER_ACCESS' => 'mcp_tool_read']);
        $this->waitForTheServer();

        self::assertSame('chosen-by-the-owner mcp_tool_read', trim($this->seen()));
        self::assertFileDoesNotExist($this->directory.'/mcp-owner-token');
    }

    public function testAHuggingFaceTokenReachesTheSeedAsTheRoutersOpenAiCompatibleAccount(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'HF_TOKEN' => 'hf-test-value']);
        $this->waitForTheServer();

        self::assertSame('https://router.huggingface.co hf-test-value', trim(new Filesystem()->readFile($this->callsFile().'.compatible')));
    }

    public function testAnOpenAiCompatibleEndpointTheOwnerSetWinsOverTheHuggingFaceToken(): void
    {
        $this->startEntrypoint([
            'INTAKT_SAMPLE_REPOSITORY' => '/data/sample',
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'HF_TOKEN' => 'hf-test-value',
            'OPENAI_COMPATIBLE_BASE_URL' => 'https://llm.example.test',
            'OPENAI_COMPATIBLE_API_KEY' => 'own-test-value',
        ]);
        $this->waitForTheServer();

        self::assertSame('https://llm.example.test own-test-value', trim(new Filesystem()->readFile($this->callsFile().'.compatible')));
    }

    public function testWithoutAHuggingFaceTokenNoOpenAiCompatibleAccountIsSet(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();

        self::assertSame('', trim(new Filesystem()->readFile($this->callsFile().'.compatible')));
    }

    public function testTwoFirstStartsAtOnceShareOneOwnerToken(): void
    {
        $first = $this->startHeldAtTheToken('1');
        $second = $this->startHeldAtTheToken('2');
        $this->awaitFile($this->directory.'/ready-1');
        $this->awaitFile($this->directory.'/ready-2');

        new Filesystem()->touch($this->directory.'/go-2');
        $second->wait();
        new Filesystem()->touch($this->directory.'/go-1');
        $first->wait();

        $kept = trim(new Filesystem()->readFile($this->directory.'/mcp-owner-token'));
        self::assertSame($kept.' mcp_tool_write', trim(new Filesystem()->readFile($this->callsFile().'-1.seen')), $first->getErrorOutput());
        self::assertSame($kept.' mcp_tool_write', trim(new Filesystem()->readFile($this->callsFile().'-2.seen')), $second->getErrorOutput());
        self::assertSame(0600, fileperms($this->directory.'/mcp-owner-token') & 0777);
    }

    public function testAnOwnerTokenThatCannotBeStoredStopsTheStartBeforeTheSeed(): void
    {
        new Filesystem()->mkdir($this->directory.'/mcp-owner-token');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('could not make or read the MCP owner token', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    public function testAMissingDataFolderStopsTheStartBeforeTheSeed(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/missing/intakt.yaml']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('could not make or read the MCP owner token', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    public function testWithoutASampleRepositoryNothingIsSeeded(): void
    {
        $this->startEntrypoint([]);

        $this->waitForTheServer();

        self::assertSame(['bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'], $this->consoleCallsBeforeTheConsumers());
    }

    public function testAFailedSeedStopsTheEntrypointBeforeTheServerStarts(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SEED_STATUS' => '1']);

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
        $this->entrypoint = $this->entrypointWith($environment);
        $this->entrypoint->start();
    }

    // A stand-in od holds each start after the token check until the test lets it go, so the test forces both starts past the check before either writes.
    private function startHeldAtTheToken(string $start): Process
    {
        $held = $this->directory.'/held';
        new Filesystem()->dumpFile($held.'/od', "#!/bin/bash\ntouch \"\$HELD/ready-\$START\"\nfor _ in {1..600}; do [[ -e \$HELD/go-\$START ]] && break; sleep 0.1; done\nprintf '%064d' \"\$START\"\n");
        new Filesystem()->chmod($held.'/od', 0755);

        $process = $this->entrypointWith([
            'PATH' => $held.':'.$this->stubPrograms().':'.getenv('PATH'),
            'HELD' => $this->directory,
            'START' => $start,
            'CALLS' => $this->callsFile().'-'.$start,
            'INTAKT_SAMPLE_REPOSITORY' => '/data/sample',
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'SEED_STATUS' => '1',
        ]);
        $process->start();

        return $process;
    }

    private function awaitFile(string $file): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && !file_exists($file)) {
            usleep(10_000);
        }

        self::assertFileExists($file);
    }

    /**
     * @param array<string, string> $environment
     */
    private function entrypointWith(array $environment): Process
    {
        // In the test's directory, so removing the cache never touches this checkout.
        $process = new Process([dirname(__DIR__, 4).'/docker/entrypoint.sh'], $this->directory, [
            'APP_ENV' => 'test',
            'PATH' => $this->stubPrograms().':'.getenv('PATH'),
            'CALLS' => $this->callsFile(),
            'APP_SECRET' => self::SECRET,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => 'http://localhost',
            'MCP_OWNER_TOKEN' => false,
            'MCP_OWNER_ACCESS' => false,
            'MESSENGER_CONSUMERS' => '1',
            'WORKER_MEMORY_CEILING_MB' => '256',
            'INTAKT_SAMPLE_REPOSITORY' => false,
            'INTAKT_CONFIG' => false,
            'HF_TOKEN' => false,
            'OPENAI_COMPATIBLE_BASE_URL' => false,
            'OPENAI_COMPATIBLE_API_KEY' => false,
            ...$environment,
        ]);
        $process->setTimeout(self::SECONDS_TO_WAIT * 2);

        return $process;
    }

    // Stand-ins that note each call; the console steps end at once (the seed with SEED_STATUS), the server and the consumers wait to be stopped, without the test's pipes, so a sleep the entrypoint leaves behind cannot hold the test.
    private function stubPrograms(): string
    {
        $programs = $this->directory.'/stubs';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($programs.'/php', "#!/bin/bash\necho \"php \$*\" >> \"\$CALLS\"\ncase \"\$*\" in *messenger:consume*) exec sleep 60 </dev/null >/dev/null 2>&1;; *intakt:sample:seed*) [[ -e var/cache/test/image-container ]] && { echo 'the image container survived until the seed' >&2; exit 3; }; echo \"\$MCP_OWNER_TOKEN \$MCP_OWNER_ACCESS\" > \"\$CALLS.seen\"; echo \"\${OPENAI_COMPATIBLE_BASE_URL:-} \${OPENAI_COMPATIBLE_API_KEY:-}\" > \"\$CALLS.compatible\"; mkdir -p var/cache/test; touch var/cache/test/seeded-container; exit \"\${SEED_STATUS:-0}\";; esac\nexit 0\n");
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

    private function seen(): string
    {
        return new Filesystem()->readFile($this->callsFile().'.seen');
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
