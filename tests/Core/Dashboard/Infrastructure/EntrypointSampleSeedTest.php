<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Runs the image's entrypoint with stand-ins for php and frankenphp, because what is proven here is the order of the start-up steps, not the steps themselves.
#[CoversNothing]
final class EntrypointSampleSeedTest extends TestCase
{
    // Generous because the parallel test workers can hold the stubbed steps back for more than ten seconds; the wait still ends at the first consumer.
    private const int SECONDS_TO_WAIT = 60;

    private const string SECRET = 'entrypoint-sample-seed-test-secret-value';

    // The POSIX number rather than SIGTERM, because that constant exists only with pcntl and the CI image has none.
    private const int TERMINATE = 15;

    // Docker's default stop timeout: a container that still runs after it is killed, so the entrypoint's cleanup is cut off.
    private const int STOP_TIMEOUT_SECONDS = 10;

    // A marked operation is held open until the stop ends it; the fallback outlasts every wait for readiness, so the stop always finds it still running, and still ends a stand-in a failed test leaves behind.
    private const int SECONDS_TO_HOLD = self::SECONDS_TO_WAIT * 2;

    // Past the nine seconds the marked operations were once held for, because the parallel test workers can hold the consumers back that long and readiness then found both ended.
    private const int SECONDS_UNTIL_LATE_READINESS = 10;

    private string $directory;

    private ?Process $entrypoint = null;

    public function testTheDemoBuildsTheContainerAgainBeforeAndAfterTheSeed(): void
    {
        self::assertSame([
            'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:sample:seed /data/sample <directory>/intakt.yaml',
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:ai:tiers',
        ], $this->runTheDefaultDemo()['consoleCalls']);
    }

    public function testARestartOnTheSameDataVolumeDoesNotPrintTheProviderNotesAgain(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();
        $this->entrypoint?->stop(15);
        new Filesystem()->remove($this->callsFile());

        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();

        self::assertNotContains('bin/console intakt:ai:tiers', $this->consoleCallsBeforeTheConsumers());
    }

    public function testFailedProviderNotesDoNotStopTheStart(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'TIERS_STATUS' => '1']);

        $this->waitForTheServer();

        self::assertStringContainsString('could not print the provider notes', (string) $this->entrypoint?->getErrorOutput());
    }

    public function testFailedProviderNotesArePrintedAgainAtTheNextStart(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'TIERS_STATUS' => '1']);
        $this->waitForTheServer();
        $this->entrypoint?->stop(15);
        new Filesystem()->remove($this->callsFile());

        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();

        self::assertContains('bin/console intakt:ai:tiers', $this->consoleCallsBeforeTheConsumers());
    }

    public function testEveryOldContainerIsRemovedBeforeItsWarmup(): void
    {
        self::assertFalse($this->runTheDefaultDemo()['cacheDirectoryExists'], 'the image container or the one the seed left survived');
    }

    public function testTheDemoMakesOneOwnerTokenAndKeepsItOnTheDataVolume(): void
    {
        $demo = $this->runTheDefaultDemo();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $demo['keptToken']);
        self::assertSame($demo['keptToken'].' mcp_tool_write', $demo['seen']);
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

    public function testTheRouterUrlComesFromTheSampleProjectDataFile(): void
    {
        $this->givenTheDataFileRouterLine('router_url:   https://router.example.test  ');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'HF_TOKEN' => 'hf-test-value']);
        $this->waitForTheServer();

        self::assertSame('https://router.example.test hf-test-value', trim(new Filesystem()->readFile($this->callsFile().'.compatible')));
    }

    public function testADataFileWithoutARouterUrlStopsAHuggingFaceStartBeforeTheSeed(): void
    {
        $this->givenTheDataFileRouterLine('  router_url: https://router.example.test');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'HF_TOKEN' => 'hf-test-value']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('names no router_url', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    public function testAQuotedRouterUrlStopsAHuggingFaceStartBeforeTheSeed(): void
    {
        $this->givenTheDataFileRouterLine("router_url: 'https://router.example.test'");
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'HF_TOKEN' => 'hf-test-value']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('names no router_url', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    public function testAMissingDataFileStopsAHuggingFaceStartBeforeTheSeed(): void
    {
        new Filesystem()->remove($this->dataFile());
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'HF_TOKEN' => 'hf-test-value']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('names no router_url', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
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
        self::assertSame('', $this->runTheDefaultDemo()['compatible']);
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
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/missing/intakt.yaml', 'MCP_OWNER_KEPT_FILE' => $this->directory.'/missing/mcp-owner-token']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('could not make or read the MCP owner token', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    public function testWithoutASampleRepositoryNothingIsSeeded(): void
    {
        $this->startEntrypoint([]);

        $this->waitForTheServer();

        self::assertNotContains('bin/console intakt:sample:seed', $this->consoleCallsBeforeTheConsumers());
    }

    public function testAStartAfterTheProjectFileWasRemovedBuildsTheContainerAgain(): void
    {
        new Filesystem()->dumpFile($this->directory.'/var/cache/test/container-with-the-removed-projects', 'old');
        $this->startEntrypoint([]);

        $this->waitForTheServer();

        self::assertSame([
            'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
            'bin/console cache:warmup --no-interaction',
        ], $this->consoleCallsBeforeTheConsumers());
        self::assertDirectoryDoesNotExist($this->directory.'/var/cache/test', 'the container compiled with the removed project file survived');
    }

    public function testAStartRemovesTheContainerCompiledIntoTheBuildDirectory(): void
    {
        new Filesystem()->dumpFile($this->directory.'/build/test/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'APP_BUILD_DIR' => $this->directory.'/build']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/build/test', 'the container in APP_BUILD_DIR survived');
    }

    public function testAStartRemovesTheCacheARelativeCacheDirectoryNames(): void
    {
        new Filesystem()->dumpFile($this->directory.'/moved-cache/test/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'APP_CACHE_DIR' => 'moved-cache']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/moved-cache/test', 'the container in the relative APP_CACHE_DIR survived');
        self::assertDirectoryExists($this->directory.'/moved-cache', 'more than the environment folder was removed');
    }

    public function testAStartRemovesTheContainerInTheBuildDirectoryADotenvFileNames(): void
    {
        $this->givenTheDotenvFileOfTheEnvironment('APP_BUILD_DIR='.$this->directory.'/dotenv-build');
        new Filesystem()->dumpFile($this->directory.'/dotenv-build/test/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/dotenv-build/test', 'the container in the APP_BUILD_DIR of .env.test.local survived');
    }

    public function testAStartRemovesTheCacheADotenvFileNames(): void
    {
        $this->givenTheDotenvFileOfTheEnvironment('APP_CACHE_DIR=dotenv-cache');
        new Filesystem()->dumpFile($this->directory.'/dotenv-cache/test/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/dotenv-cache/test', 'the container in the APP_CACHE_DIR of .env.test.local survived');
    }

    public function testABuildDirectoryInTheEnvironmentWinsOverTheOneADotenvFileNames(): void
    {
        $this->givenTheDotenvFileOfTheEnvironment('APP_BUILD_DIR='.$this->directory.'/dotenv-build');
        new Filesystem()->dumpFile($this->directory.'/dotenv-build/test/not-the-kernels', 'kept');
        new Filesystem()->dumpFile($this->directory.'/build/test/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'APP_BUILD_DIR' => $this->directory.'/build']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/build/test', 'the container in the environment\'s APP_BUILD_DIR survived');
        self::assertFileExists($this->directory.'/dotenv-build/test/not-the-kernels', 'a folder the kernel does not compile into was removed');
    }

    public function testAStartRemovesTheContainerInTheBuildDirectoryOfTheDotenvPathTheRuntimeOptionsName(): void
    {
        new Filesystem()->dumpFile($this->directory.'/config/moved.env', 'APP_BUILD_DIR='.$this->directory."/moved-build\n");
        new Filesystem()->dumpFile($this->directory.'/moved-build/test/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'APP_RUNTIME_OPTIONS' => '{"dotenv_path":"config/moved.env"}']);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/moved-build/test', 'the container in the APP_BUILD_DIR of the runtime\'s dotenv_path survived');
    }

    public function testAStartRemovesTheContainerOfTheEnvironmentADotenvFileNames(): void
    {
        new Filesystem()->dumpFile($this->directory.'/.env.local', "APP_ENV=staging\nAPP_BUILD_DIR=".$this->directory."/build\n");
        new Filesystem()->dumpFile($this->directory.'/build/staging/old-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'APP_ENV' => false]);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/build/staging', 'the container of the APP_ENV in .env.local survived');
    }

    public function testAStartRemovesTheContainerOfTheEnvironmentTheConsolePassesToTheKernel(): void
    {
        new Filesystem()->dumpFile($this->directory.'/build/test/old-container', 'old');
        new Filesystem()->dumpFile($this->directory.'/build/staging/keep', 'kept');
        $this->startEntrypoint([
            'INTAKT_CONFIG' => $this->directory.'/intakt.yaml',
            'OTHER_ENV' => 'staging',
            'APP_RUNTIME_OPTIONS' => '{"env_var_name":"OTHER_ENV"}',
            'APP_BUILD_DIR' => $this->directory.'/build',
        ]);

        $this->waitForTheServer();

        self::assertDirectoryDoesNotExist($this->directory.'/build/test', 'the container of the APP_ENV bin/console passes to the kernel survived');
        self::assertFileExists($this->directory.'/build/staging/keep', 'the folder of an environment the kernel does not run in was removed');
    }

    public function testACacheDirectoryOfTwoLinesStopsTheStartBeforeAnythingIsRemoved(): void
    {
        $cache = $this->directory."/cache\n".$this->directory.'/unrelated';
        $markers = [$cache.'/test/old', $this->directory.'/build/test/old', $this->directory.'/unrelated/test/keep'];
        array_map(static fn (string $marker) => new Filesystem()->dumpFile($marker, 'kept'), $markers);
        $this->startEntrypoint(['APP_CACHE_DIR' => $cache, 'APP_BUILD_DIR' => $this->directory.'/build']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('APP_CACHE_DIR', (string) $this->entrypoint?->getErrorOutput());
        self::assertSame([true, true, true], array_map(is_file(...), $markers), 'a folder was removed although the start was refused');
        self::assertStringNotContainsString('cache:warmup', $this->calls());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    public function testABuildDirectoryWithACarriageReturnStopsTheStartBeforeAnythingIsRemoved(): void
    {
        $build = $this->directory."/build\r";
        $markers = [$this->directory.'/var/cache/test/old', $build.'/test/old'];
        array_map(static fn (string $marker) => new Filesystem()->dumpFile($marker, 'kept'), $markers);
        $this->startEntrypoint(['APP_BUILD_DIR' => $build]);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('APP_BUILD_DIR', (string) $this->entrypoint?->getErrorOutput());
        self::assertSame([true, true], array_map(is_file(...), $markers), 'a folder was removed although the start was refused');
        self::assertStringNotContainsString('cache:warmup', $this->calls());
    }

    public function testACacheDirectoryWithANulByteFromEnvLocalPhpStopsTheStartBeforeAnythingIsRemoved(): void
    {
        new Filesystem()->dumpFile($this->directory.'/.env.local.php', '<?php return '.var_export(['APP_CACHE_DIR' => $this->directory."/cache\0/shifted"], true).";\n");
        $markers = [$this->directory.'/cache/shifted/test/keep', $this->directory.'/var/cache/test/old', $this->directory.'/build/test/old'];
        array_map(static fn (string $marker) => new Filesystem()->dumpFile($marker, 'kept'), $markers);
        $this->startEntrypoint(['APP_BUILD_DIR' => $this->directory.'/build']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('APP_CACHE_DIR holds a control character', (string) $this->entrypoint?->getErrorOutput());
        self::assertSame([true, true, true], array_map(is_file(...), $markers), 'a folder was removed although the start was refused');
        self::assertStringNotContainsString('cache:warmup', $this->calls());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    public function testABuildDirectoryWithATabStopsTheStartBeforeAnythingIsRemoved(): void
    {
        $build = $this->directory."/build\t";
        $markers = [$this->directory.'/var/cache/test/old', $build.'/test/old'];
        array_map(static fn (string $marker) => new Filesystem()->dumpFile($marker, 'kept'), $markers);
        $this->startEntrypoint(['APP_BUILD_DIR' => $build]);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('APP_BUILD_DIR holds a control character', (string) $this->entrypoint?->getErrorOutput());
        self::assertSame([true, true], array_map(is_file(...), $markers), 'a folder was removed although the start was refused');
        self::assertStringNotContainsString('cache:warmup', $this->calls());
    }

    public function testAnEnvironmentOfTwoLinesStopsTheStartBeforeAnythingIsRemoved(): void
    {
        new Filesystem()->dumpFile($this->directory.'/var/cache/test/old', 'kept');
        $this->startEntrypoint(['APP_ENV' => "test\nstaging"]);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('APP_ENV', (string) $this->entrypoint?->getErrorOutput());
        self::assertFileExists($this->directory.'/var/cache/test/old');
        self::assertStringNotContainsString('cache:warmup', $this->calls());
    }

    #[DataProvider('readsThatAreNotTheThreeValues')]
    public function testAReadThatDoesNotPrintExactlyTheThreeValuesStopsTheStartBeforeAnythingIsRemoved(string $printed): void
    {
        $markers = [$this->directory.'/var/cache/test/old', $this->directory.'/shifted/test/keep'];
        array_map(static fn (string $marker) => new Filesystem()->dumpFile($marker, 'kept'), $markers);
        $reading = $this->directory.'/reading';
        new Filesystem()->dumpFile($reading.'/php', "#!/bin/bash\n[[ \$1 == -r ]] && { printf '%s' \"\$PRINTED\"; exit 0; }\nexec ".escapeshellarg($this->stubPrograms().'/php')." \"\$@\"\n");
        new Filesystem()->chmod($reading.'/php', 0755);
        $this->startEntrypoint(['PATH' => $reading.':'.$this->stubPrograms().':'.getenv('PATH'), 'PRINTED' => $printed]);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertSame([true, true], array_map(is_file(...), $markers), 'a folder was removed although the start was refused');
        self::assertStringNotContainsString('cache:warmup', $this->calls());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readsThatAreNotTheThreeValues(): iterable
    {
        yield 'a fourth line' => ["APP_ENV=test\nAPP_CACHE_DIR=cache\nshifted\nAPP_BUILD_DIR=\n"];
        yield 'two lines' => ["APP_ENV=test\nAPP_CACHE_DIR=shifted\n"];
        yield 'three lines without their names' => ["test\n\nshifted\n"];
        yield 'the values in another order' => ["APP_ENV=test\nAPP_BUILD_DIR=shifted\nAPP_CACHE_DIR=\n"];
    }

    public function testADotenvFileThatCannotBeReadStopsTheStartBeforeTheServer(): void
    {
        $this->givenTheDotenvFileOfTheEnvironment('APP_BUILD_DIR="never closed');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringNotContainsString('cache:warmup', $this->calls());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    public function testAStartWithAProjectFileBuildsTheContainerAgainBeforeTheWorkers(): void
    {
        new Filesystem()->dumpFile($this->directory.'/var/cache/test/image-container', 'old');
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);

        $this->waitForTheServer();

        self::assertSame([
            'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
            'bin/console cache:warmup --no-interaction',
        ], $this->consoleCallsBeforeTheConsumers());
        self::assertDirectoryDoesNotExist($this->directory.'/var/cache/test', 'the container the image compiled without the project file survived');
    }

    public function testAProjectFileAtItsDefaultPlaceBuildsTheContainerAgainToo(): void
    {
        new Filesystem()->dumpFile($this->directory.'/config/intakt.yaml', 'projects: {}');
        $this->startEntrypoint([]);

        $this->waitForTheServer();

        self::assertContains('bin/console cache:warmup --no-interaction', $this->consoleCallsBeforeTheConsumers());
    }

    public function testAProjectFileTheContainerCannotBeBuiltFromStopsTheStartBeforeTheServer(): void
    {
        $this->startEntrypoint(['INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'WARMUP_STATUS' => '1']);

        $this->entrypoint?->wait();

        self::assertSame(1, $this->entrypoint?->getExitCode());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
        self::assertStringNotContainsString('messenger:consume', $this->calls());
    }

    public function testTheComposeDemoCreatesTheDemoProjectAfterTheSeed(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'INTAKT_COMPOSE_SERVICE' => 'intakt']);

        $this->waitForTheServer();

        self::assertSame([
            'bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration',
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:sample:seed /data/sample '.$this->directory.'/intakt.yaml',
            'bin/console cache:warmup --no-interaction',
            'bin/console intakt:demo:create',
            'bin/console intakt:ai:tiers',
        ], $this->consoleCallsBeforeTheConsumers());
    }

    public function testASampleStartedOutsideComposeCreatesNoDemoProject(): void
    {
        self::assertNotContains('bin/console intakt:demo:create', $this->runTheDefaultDemo()['consoleCalls']);
    }

    public function testAComposeServiceWithoutASampleRepositoryCreatesNoDemoProject(): void
    {
        $this->startEntrypoint(['INTAKT_COMPOSE_SERVICE' => 'intakt']);

        $this->waitForTheServer();

        self::assertNotContains('bin/console intakt:demo:create', $this->consoleCallsBeforeTheConsumers());
    }

    public function testAFailedDemoProjectStopsTheEntrypointBeforeTheServerStarts(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'INTAKT_COMPOSE_SERVICE' => 'intakt', 'CREATE_STATUS' => '1']);

        $this->entrypoint?->wait();

        self::assertSame(1, $this->entrypoint?->getExitCode());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    public function testAFailedSeedStopsTheEntrypointBeforeTheServerStarts(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SEED_STATUS' => '1']);

        $this->entrypoint?->wait();

        self::assertSame(1, $this->entrypoint?->getExitCode());
        self::assertStringNotContainsString('frankenphp run', $this->calls());
    }

    public function testTheDashboardAddressIsPrintedOnlyOnceTheServerAnswersItsHealthCheckAndTheDashboard(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'CURL_FAILURES' => '2']);

        $this->waitForTheAddress();

        $calls = explode("\n", $this->calls());
        $asked = array_keys(array_filter($calls, static fn (string $call): bool => str_starts_with($call, 'curl ')));
        self::assertCount(4, $asked, $this->calls());
        self::assertStringEndsWith('http://localhost:2019/metrics', $calls[$asked[2]]);
        self::assertStringEndsWith('http://localhost:7781/', $calls[$asked[3]]);
    }

    public function testTheStartMessageAsksTheServerPortWithThePublishedHostAndPrintsThePublishedAddress(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SERVER_PORT' => '7781', 'DASHBOARD_PORT' => '8080']);

        $this->waitForTheAddress('http://localhost:8080');

        self::assertStringContainsString('curl --fail --silent --max-time 2 --output /dev/null -H Host: localhost:8080 http://localhost:7781/', $this->calls());
        self::assertStringEndsWith('http://localhost:8080', trim((string) $this->entrypoint?->getOutput()));
    }

    public function testTheDemoStartPrintsTheDashboardAddressAndNotTheReviewCommand(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheAddress();

        $this->waitForMorePolls();

        self::assertStringContainsString('http://localhost:7781', (string) $this->entrypoint?->getOutput());
        self::assertStringNotContainsString('intakt:sample:show', $this->calls());
    }

    public function testTheDashboardAddressIsTheLastThingTheStartPrints(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'CURL_FAILURES' => '1']);
        $this->waitForTheAddress();

        $this->waitForMorePolls();

        $output = trim((string) $this->entrypoint?->getOutput());
        self::assertStringContainsString('the server started', $output);
        self::assertStringEndsWith('http://localhost:7781', $output);
    }

    public function testTheDashboardAddressIsPrintedOnceNotOnEveryPoll(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheAddress();
        $asked = substr_count($this->calls(), "\ncurl ");

        $this->waitForMorePolls();

        self::assertSame(1, substr_count((string) $this->entrypoint?->getOutput(), 'http://localhost:7781'));
        self::assertSame($asked, substr_count($this->calls(), "\ncurl "));
    }

    public function testAStopDuringASlowHealthCheckEndsInsideDockersStopTimeout(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'CURL_SECONDS' => (string) self::SECONDS_TO_HOLD, 'CONSUMERS_IGNORE_SIGTERM' => '1']);
        $this->waitForTheCall("\ncurl ");
        $this->waitForTheConsumersToIgnoreTheStop();

        $seconds = $this->secondsToStop();

        self::assertSame(0, $this->entrypoint?->getExitCode(), (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('the slow curl ended', $this->calls(), 'the stop waited for the health check');
        self::assertLessThan(self::STOP_TIMEOUT_SECONDS, $seconds);
    }

    public function testAStopOnAMachineWhereEveryPauseOverrunsStillEndsInsideDockersStopTimeout(): void
    {
        $this->startEntrypoint(['PATH' => $this->overrunningSleep().':'.$this->stubPrograms().':'.getenv('PATH'), 'CONSUMERS_IGNORE_SIGTERM' => '1']);
        $this->waitForTheServer();
        $this->waitForTheCall('the poll sleeps');
        $this->waitForTheConsumersToIgnoreTheStop();

        $seconds = $this->secondsToStop();

        self::assertSame(0, $this->entrypoint?->getExitCode(), (string) $this->entrypoint?->getErrorOutput());
        self::assertLessThan(self::STOP_TIMEOUT_SECONDS, $seconds);
    }

    public function testTheMarkedPollAndTheSlowHealthCheckAreStillOpenWhenTheConsumersAreReadyLate(): void
    {
        $environment = ['CALLS' => $this->callsFile(), 'CURL_SECONDS' => (string) self::SECONDS_TO_HOLD];
        $poll = new Process([$this->overrunningSleep().'/sleep', '1'], $this->directory, $environment);
        $curl = new Process([$this->stubPrograms().'/curl'], $this->directory, $environment);
        $poll->start();
        $curl->start();

        usleep(self::SECONDS_UNTIL_LATE_READINESS * 1_000_000);

        self::assertTrue($poll->isRunning(), 'the marked poll ended before the stop');
        self::assertTrue($curl->isRunning(), 'the slow curl ended before the stop');
        $poll->signal(self::TERMINATE);
        $curl->signal(self::TERMINATE);
        $poll->wait();
        $curl->wait();
        self::assertStringNotContainsString('the slow curl ended', $this->calls());
    }

    public function testWithoutASampleRepositoryTheStartNeverAsksForTheDashboardAddress(): void
    {
        $this->startEntrypoint([]);
        $this->waitForTheServer();

        $this->waitForMorePolls();

        self::assertStringNotContainsString('curl ', $this->calls());
        self::assertStringNotContainsString('http://localhost:7781', (string) $this->entrypoint?->getOutput());
    }

    public function testTheSampleIsMarkedReadyOnlyAfterTheSeed(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SEED_STATUS' => '1']);
        $this->entrypoint?->wait();
        self::assertFileDoesNotExist($this->directory.'/var/sample-ready');

        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();

        self::assertFileExists($this->directory.'/var/sample-ready');
    }

    public function testARestartClearsTheReadyMarkBeforeTheSeedRunsAgain(): void
    {
        new Filesystem()->dumpFile($this->directory.'/var/sample-ready', '');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SEED_STATUS' => '1']);

        $this->entrypoint?->wait();

        self::assertFileDoesNotExist($this->directory.'/var/sample-ready');
    }

    public function testEmptySecretsAreMadeOnTheFirstStartAndKeptOnTheDataVolume(): void
    {
        $this->startEntrypoint(['APP_SECRET' => '', 'MERCURE_JWT_SECRET' => '', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SECRETS_SEEN' => $this->directory.'/secrets']);
        $this->waitForTheServer();

        $app = trim(new Filesystem()->readFile($this->directory.'/app-secret'));
        $mercure = trim(new Filesystem()->readFile($this->directory.'/mercure-jwt-secret'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $app);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $mercure);
        self::assertNotSame($app, $mercure);
        self::assertSame($app.' '.$mercure, trim(new Filesystem()->readFile($this->directory.'/secrets')));
        self::assertSame(0600, fileperms($this->directory.'/app-secret') & 0777);
        self::assertStringNotContainsString($app, $this->entrypoint?->getOutput().$this->entrypoint?->getErrorOutput());
    }

    public function testARestartOnTheSameDataVolumeReusesTheMadeSecrets(): void
    {
        $environment = ['APP_SECRET' => '', 'MERCURE_JWT_SECRET' => '', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SECRETS_SEEN' => $this->directory.'/secrets'];
        $this->startEntrypoint($environment);
        $this->waitForTheServer();
        $this->entrypoint?->stop(15);
        $first = trim(new Filesystem()->readFile($this->directory.'/secrets'));
        new Filesystem()->remove($this->callsFile());

        $this->startEntrypoint($environment);
        $this->waitForTheServer();

        self::assertSame($first, trim(new Filesystem()->readFile($this->directory.'/secrets')));
    }

    public function testASecretTheOwnerSetWinsAndIsNotWrittenToTheVolume(): void
    {
        $this->startEntrypoint(['APP_SECRET' => 'chosen-by-the-owner', 'MERCURE_JWT_SECRET' => '', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml', 'SECRETS_SEEN' => $this->directory.'/secrets']);
        $this->waitForTheServer();

        self::assertStringStartsWith('chosen-by-the-owner ', trim(new Filesystem()->readFile($this->directory.'/secrets')));
        self::assertFileDoesNotExist($this->directory.'/app-secret');
        self::assertFileExists($this->directory.'/mercure-jwt-secret');
    }

    public function testAnEmptySecretWithoutADataFolderIsNamed(): void
    {
        $this->startEntrypoint(['APP_SECRET' => '']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('set APP_SECRET, or set INTAKT_CONFIG', (string) $this->entrypoint?->getErrorOutput());
    }

    public function testAnEmptySecretThatCannotBeStoredStopsTheStart(): void
    {
        $this->startEntrypoint(['APP_SECRET' => '', 'INTAKT_CONFIG' => $this->directory.'/missing/intakt.yaml']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('could not make or read APP_SECRET', (string) $this->entrypoint?->getErrorOutput());
    }

    public function testTwoFirstStartsAtOnceShareOneMadeSecret(): void
    {
        $first = $this->startHeldAtTheToken('1', ['APP_SECRET' => '']);
        $second = $this->startHeldAtTheToken('2', ['APP_SECRET' => '']);
        $this->awaitFile($this->directory.'/ready-1');
        $this->awaitFile($this->directory.'/ready-2');

        new Filesystem()->touch($this->directory.'/go-2');
        $second->wait();
        new Filesystem()->touch($this->directory.'/go-1');
        $first->wait();

        $kept = trim(new Filesystem()->readFile($this->directory.'/app-secret'));
        self::assertSame($kept.' '.self::SECRET, trim(new Filesystem()->readFile($this->directory.'/secrets-1')), $first->getErrorOutput());
        self::assertSame($kept.' '.self::SECRET, trim(new Filesystem()->readFile($this->directory.'/secrets-2')), $second->getErrorOutput());
    }

    public function testASampleRepositoryWithoutAProjectFileIsRefusedByName(): void
    {
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample']);

        $this->entrypoint?->wait();

        self::assertNotSame(0, $this->entrypoint?->getExitCode());
        self::assertStringContainsString('INTAKT_CONFIG', (string) $this->entrypoint?->getErrorOutput());
        self::assertStringNotContainsString('intakt:sample:seed', $this->calls());
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-entrypoint-seed-'.bin2hex(random_bytes(6));
        new Filesystem()->copy(dirname(__DIR__, 4).'/config/sample-project.yaml', $this->dataFile());
        // Linked and written here because the start asks Symfony Runtime for the kernel's environment and folders with the real PHP, which reads both from its working directory.
        new Filesystem()->symlink(dirname(__DIR__, 4).'/vendor', $this->directory.'/vendor');
        new Filesystem()->dumpFile($this->directory.'/.env', '');
    }

    protected function tearDown(): void
    {
        $this->entrypoint?->stop(15);
        new Filesystem()->remove($this->directory);
    }

    /**
     * @param array<string, false|string> $environment
     */
    private function startEntrypoint(array $environment): void
    {
        $this->entrypoint = $this->entrypointWith($environment);
        $this->entrypoint->start();
    }

    // A stand-in od holds each start after the token check until the test lets it go, so the test forces both starts past the check before either writes.
    /**
     * @param array<string, string> $environment
     */
    private function startHeldAtTheToken(string $start, array $environment = []): Process
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
            'SECRETS_SEEN' => $this->directory.'/secrets-'.$start,
            ...$environment,
        ]);
        $process->start();

        return $process;
    }

    // A stand-in sleep that makes the stop's pauses of a tenth of a second take three times as long, as on a loaded machine (a test pass measured a stop of 10.7 s), so the test forces a stop whose pauses all overrun; it holds the poll's one-second pause open until the stop ends it as a job, so the pause is still running when the test sends the stop.
    private function overrunningSleep(): string
    {
        $folder = $this->directory.'/overrunning';
        $sleep = new ExecutableFinder()->find('sleep');
        self::assertNotNull($sleep);
        new Filesystem()->dumpFile($folder.'/sleep', "#!/bin/bash\ncase \$1 in 0.1) set -- 0.3;; 1) echo 'the poll sleeps' >> \"\$CALLS\"; set -- ".self::SECONDS_TO_HOLD.";; esac\nexec ".escapeshellarg($sleep)." \"\$@\"\n");
        new Filesystem()->chmod($folder.'/sleep', 0755);

        return $folder;
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
     * @param array<string, false|string> $environment
     */
    private function entrypointWith(array $environment): Process
    {
        // In the test's directory, so removing the cache never touches this checkout.
        $process = new Process([dirname(__DIR__, 4).'/docker/entrypoint.sh'], $this->directory, [
            'APP_ENV' => 'test',
            // Cleared, because the start removes the folders they name and a test worker's own cache would be among them.
            'APP_CACHE_DIR' => false,
            'APP_BUILD_DIR' => false,
            'PATH' => $this->stubPrograms().':'.getenv('PATH'),
            'CALLS' => $this->callsFile(),
            'APP_SECRET' => self::SECRET,
            'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_JWT_ISSUER' => 'http://localhost',
            'MCP_OWNER_TOKEN' => false,
            'MCP_OWNER_KEPT_FILE' => $this->directory.'/mcp-owner-token',
            'MCP_OWNER_ACCESS' => false,
            'MESSENGER_CONSUMERS' => '1',
            'WORKER_MEMORY_CEILING_MB' => '256',
            'DASHBOARD_PORT' => '7781',
            'CADDY_ADMIN' => false,
            'INTAKT_SAMPLE_REPOSITORY' => false,
            'INTAKT_CONFIG' => false,
            'INTAKT_COMPOSE_SERVICE' => false,
            'HF_TOKEN' => false,
            'OPENAI_COMPATIBLE_BASE_URL' => false,
            'OPENAI_COMPATIBLE_API_KEY' => false,
            ...$environment,
        ]);
        $process->setTimeout(self::SECONDS_TO_WAIT * 2);

        return $process;
    }

    /**
     * @return array{consoleCalls: list<string>, cacheDirectoryExists: bool, keptToken: string, seen: string, compatible: string}
     */
    private function runTheDefaultDemo(): array
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/var/cache/test/image-container', 'old');
        $this->startEntrypoint(['INTAKT_SAMPLE_REPOSITORY' => '/data/sample', 'INTAKT_CONFIG' => $this->directory.'/intakt.yaml']);
        $this->waitForTheServer();

        return [
            'consoleCalls' => array_map(fn (string $call): string => str_replace($this->directory, '<directory>', $call), $this->consoleCallsBeforeTheConsumers()),
            'cacheDirectoryExists' => is_dir($this->directory.'/var/cache/test'),
            'keptToken' => trim($filesystem->readFile($this->directory.'/mcp-owner-token')),
            'seen' => trim($this->seen()),
            'compatible' => trim($filesystem->readFile($this->callsFile().'.compatible')),
        ];
    }

    // This file because Symfony's Dotenv reads it last for the test environment these starts run in, so nothing overrides its line.
    private function givenTheDotenvFileOfTheEnvironment(string $line): void
    {
        new Filesystem()->dumpFile($this->directory.'/.env.test.local', $line."\n");
    }

    // Stand-ins that note each call (the inline script that asks Symfony Runtime for the kernel's environment and folders goes to the real PHP unnoted, because its answer is what the start acts on); the console steps end at once (the seed with SEED_STATUS), the server and the consumers wait to be stopped, without the test's pipes, so a sleep the entrypoint leaves behind cannot hold the test.
    private function stubPrograms(): string
    {
        $programs = $this->directory.'/stubs';
        $filesystem = new Filesystem();
        // A consumer started without --quiet prints Symfony's banner (the success line to standard output, the comments to standard error) only once the review block is out (bounded), so the test forces the order a slow consumer start takes; with CONSUMERS_IGNORE_SIGTERM a consumer ignores the stop like one whose worker does not exist yet (upstream/messenger-consume-ignores-early-sigterm), so the stop runs its whole grace period; with SHOW_SECONDS the review block takes that long and notes its end, so the test forces a stop that arrives while it runs.
        $filesystem->dumpFile($programs.'/php', "#!/bin/bash\n[[ \$1 == -r ]] && exec ".escapeshellarg(PHP_BINARY)." \"\$@\"\necho \"php \$*\" >> \"\$CALLS\"\ncase \"\$*\" in *messenger:consume*) [[ \" \$* \" == *' --quiet '* ]] || { for _ in {1..600}; do grep -q intakt:sample:show \"\$CALLS\" && break; sleep 0.1; done; sleep 0.3; echo ' [OK] Consuming messages from transport.'; echo ' // Quit the worker with CONTROL-C.' >&2; }; [[ -n \${CONSUMERS_IGNORE_SIGTERM:-} ]] && { trap '' TERM; echo 'a consumer ignores the stop' >> \"\$CALLS\"; }; exec sleep 60 </dev/null >/dev/null 2>&1;; *intakt:sample:seed*) [[ -e var/cache/test/image-container ]] && { echo 'the image container survived until the seed' >&2; exit 3; }; echo \"\$MCP_OWNER_TOKEN \$MCP_OWNER_ACCESS\" > \"\$CALLS.seen\"; echo \"\${OPENAI_COMPATIBLE_BASE_URL:-} \${OPENAI_COMPATIBLE_API_KEY:-}\" > \"\$CALLS.compatible\"; [[ -n \${SECRETS_SEEN:-} ]] && echo \"\$APP_SECRET \$MERCURE_JWT_SECRET\" > \"\$SECRETS_SEEN\"; mkdir -p var/cache/test; touch var/cache/test/seeded-container; exit \"\${SEED_STATUS:-0}\";; *cache:warmup*) exit \"\${WARMUP_STATUS:-0}\";; *intakt:demo:create*) exit \"\${CREATE_STATUS:-0}\";; *intakt:ai:tiers*) exit \"\${TIERS_STATUS:-0}\";; *intakt:sample:show*) [[ -n \${SHOW_SECONDS:-} ]] && { sleep \"\$SHOW_SECONDS\" </dev/null >/dev/null 2>&1; echo 'the slow show ended' >> \"\$CALLS\"; }; echo 'the review block'; exit \"\${SHOW_STATUS:-0}\";; esac\nexit 0\n");
        // The server notes its run only after the scheduler watcher (bounded): the entrypoint starts them in parallel, so the test forces the order it must survive.
        $filesystem->dumpFile($programs.'/frankenphp', "#!/bin/bash\ncase \"\$*\" in *validate*) ;; *) for _ in {1..600}; do grep -q scheduler_watcher \"\$CALLS\" && break; sleep 0.1; done;; esac\necho \"frankenphp \$*\" >> \"\$CALLS\"\ncase \"\$*\" in *validate*) [[ -n \${SECRETS_SEEN:-} ]] && echo \"\$APP_SECRET \$MERCURE_JWT_SECRET\" > \"\$SECRETS_SEEN\"; exit 0;; esac\necho 'the server started'\nexec sleep 60 </dev/null >/dev/null 2>&1\n");
        // The health check answers only once the server runs and after CURL_FAILURES refused calls, so the test forces a start where the server is not up yet; with CURL_SECONDS every call takes that long and notes its end, so the test forces a stop that arrives while one runs; the TERM trap is its first statement and kills the pause only once one started ($! stays empty until then), so a stop in any order ends the call and no pause outlives the test.
        $filesystem->dumpFile($programs.'/curl', "#!/bin/bash\ntrap '[[ -n \$! ]] && kill \$! 2>/dev/null; exit 143' TERM\necho \"curl \$*\" >> \"\$CALLS\"\n[[ -n \${CURL_SECONDS:-} ]] && { sleep \"\$CURL_SECONDS\" </dev/null >/dev/null 2>&1 & wait \$!; echo 'the slow curl ended' >> \"\$CALLS\"; }\ngrep -q '^frankenphp run' \"\$CALLS\" || exit 7\n(( \$(grep -c '^curl ' \"\$CALLS\") > \${CURL_FAILURES:-0} )) || exit 7\nexit 0\n");
        $filesystem->chmod([$programs.'/php', $programs.'/frankenphp', $programs.'/curl'], 0755);

        return $programs;
    }

    private function waitForTheAddress(string $address = 'http://localhost:7781'): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && !str_contains((string) $this->entrypoint?->getOutput(), $address)) {
            usleep(50_000);
        }

        self::assertStringContainsString($address, (string) $this->entrypoint?->getOutput(), (string) $this->entrypoint?->getErrorOutput());
    }

    private function waitForTheCall(string $call): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && !str_contains($this->calls(), $call)) {
            usleep(50_000);
        }

        self::assertStringContainsString($call, $this->calls(), (string) $this->entrypoint?->getErrorOutput());
    }

    // Two and a half polls of the entrypoint's one-second loop, so a step it repeats or prints later would have run.
    private function waitForMorePolls(): void
    {
        usleep(2_500_000);
    }

    private function waitForTheConsumersToIgnoreTheStop(): void
    {
        // The async consumer (MESSENGER_CONSUMERS is 1) and the scheduler watcher: a count of the consumers seen so far would pass with only the first one up.
        $consumers = 2;
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && substr_count($this->calls(), 'a consumer ignores the stop') < $consumers) {
            usleep(50_000);
        }

        self::assertSame($consumers, substr_count($this->calls(), 'a consumer ignores the stop'), (string) $this->entrypoint?->getErrorOutput());
    }

    // The signal reaches only the entrypoint, as a container stop does, so its children end only when its trap runs.
    private function secondsToStop(): float
    {
        $sent = hrtime(true);
        $this->entrypoint?->signal(self::TERMINATE);
        $this->entrypoint?->wait();

        return (hrtime(true) - $sent) / 1_000_000_000;
    }

    private function waitForTheServer(): void
    {
        $deadline = hrtime(true) + self::SECONDS_TO_WAIT * 1_000_000_000;

        while (hrtime(true) < $deadline && (!str_contains($this->calls(), 'frankenphp run') || !str_contains($this->calls(), 'messenger:consume scheduler_watcher'))) {
            usleep(50_000);
        }

        self::assertStringContainsString('frankenphp run', $this->calls(), (string) $this->entrypoint?->getErrorOutput());
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

    private function givenTheDataFileRouterLine(string $line): void
    {
        $filesystem = new Filesystem();
        $contents = preg_replace('/^router_url:.*$/m', $line, $filesystem->readFile($this->dataFile()), 1, $replaced);
        self::assertSame(1, $replaced);
        $filesystem->dumpFile($this->dataFile(), (string) $contents);
    }

    // Where the image's working directory holds it, because the entrypoint reads it by that relative path.
    private function dataFile(): string
    {
        return $this->directory.'/config/sample-project.yaml';
    }

    private function callsFile(): string
    {
        return $this->directory.'/calls';
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
}
