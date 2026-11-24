<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('instance')]
final class InstanceWorkerModeTest extends InstanceTestCase
{
    // Never skipped: this is the only pull-request proof that a worker-mode boot gets ready, and bin/instance writes its own Caddyfile, so CaddyfileWorkersTest does not cover its app worker; one boot for the lookup and the readiness facts, because each worker-mode boot compiles the real kernel's container cold.
    public function testAFrankenphpFoundThroughARelativePathEntryFromAnotherDirectoryServesTheReadinessRequestThroughTheAppWorkerOnLoopbackAndLeavesNothing(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');
        self::assertNotNull($frankenphp, 'frankenphp must be on the PATH, as in the image and the build sandbox.');
        $caller = $this->scratch.'/caller';
        mkdir($caller.'/tools', recursive: true);
        symlink($frankenphp, $caller.'/tools/frankenphp');
        // The socket table, because the site address names no host and only bind keeps the listener on loopback; the polling, because FrankenPHP counts a request only after the answer is sent.
        $command = <<<'PHP_WRAP'
        $port = (int) parse_url((string) getenv("INTAKT_INSTANCE_URL"), PHP_URL_PORT);
        $addresses = [];
        foreach (["/proc/net/tcp", "/proc/net/tcp6"] as $table) {
            foreach (file($table) ?: [] as $line) {
                $columns = preg_split("/\s+/", trim($line));
                if ("0A" !== ($columns[3] ?? "")) { continue; }
                [$address, $hexPort] = explode(":", $columns[1]);
                if (hexdec($hexPort) === $port) { $addresses[] = $address; }
            }
        }
        foreach (glob("/proc/[0-9]*/cmdline") ?: [] as $file) {
            $arguments = explode("\0", (string) @file_get_contents($file));
            if (!str_ends_with($arguments[0], "frankenphp") || !str_starts_with($arguments[3] ?? "", $argv[1])) { continue; }
            foreach (explode("\0", (string) @file_get_contents(dirname($file)."/environ")) as $entry) {
                if (str_starts_with($entry, "FRANKENPHP_ADMIN_URL=")) { echo "server admin url: ", "FRANKENPHP_ADMIN_URL=".getenv("INTAKT_INSTANCE_ADMIN_URL") === $entry ? "own" : "other", "\n"; }
            }
        }
        echo "listeners: ", implode(",", array_unique($addresses)), "\n";
        for ($i = 0; $i < 100; ++$i) {
            $states = json_decode((string) file_get_contents(getenv("INTAKT_INSTANCE_ADMIN_URL")."/frankenphp/threads"), true)["ThreadDebugStates"] ?? [];
            foreach ($states as $state) {
                if (str_starts_with($state["Name"], "Worker ") && $state["RequestCount"] > 0) { echo $state["Name"], " served ", $state["RequestCount"], "\n"; exit(0); }
            }
            usleep(100_000);
        }
        exit(3);
        PHP_WRAP;

        // Only the server this run launched is read, by its Caddyfile in this test's scratch folder, and only whether its value is the instance's own admin address is printed.
        $run = $this->runInstanceFrom($caller, ['PATH' => 'tools:'.$this->pathWithoutFrankenphp(), 'FRANKENPHP_ADMIN_URL' => 'http://localhost:2019'], [PHP_BINARY, '-r', $command, $this->scratch.'/tmp/']);

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame(0, $run['exit']);
        self::assertMatchesRegularExpression('#^Worker PHP Thread - '.preg_quote((string) realpath(self::ROOT), '#').'/public/index\.php served [1-9]\d*$#m', $run['output']);
        self::assertMatchesRegularExpression('/^listeners: 0100007F$/m', $run['output']);
        self::assertSame(['server admin url: own'], preg_grep('/^server admin url: /', explode("\n", $run['output'])) |> array_values(...));
        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame([], $this->processesNaming($this->scratch.'/tmp'));
    }

    public function testInWorkerModeARequestNamingTheDashboardHostIsAnsweredByTheAppWithItsPage(): void
    {
        $dashboardPort = new Dotenv()->parse((string) file_get_contents(self::ROOT.'/.env.test'))['DASHBOARD_PORT'] ?? null;
        self::assertIsString($dashboardPort);
        $dashboardHost = 'localhost:'.$dashboardPort;
        $request = <<<'PHP_WRAP'
            $context = stream_context_create(["http" => ["header" => "Host: ".$argv[1], "ignore_errors" => true]]);
            $body = (string) file_get_contents(getenv("INTAKT_INSTANCE_URL")."/projects", false, $context);
            echo array_first(http_get_last_response_headers() ?? []), "\n", str_contains($body, "No project has started a review yet.") ? "empty projects page\n" : "no projects page\n";
            PHP_WRAP;
        // Migrated first, because the page reads the ledger and bin/instance starts on an empty database.
        $command = ['sh', '-c', '"$0" bin/console --no-interaction --quiet doctrine:migrations:migrate && "$0" -r "$1" "$2"', PHP_BINARY, $request, $dashboardHost];

        $run = $this->runInstance(self::ROOT, $command, $this->environmentWithFrankenphp());

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame("HTTP/1.1 200 OK\nempty projects page\n", $run['output']);
    }

    public function testInWorkerModeTheBoardShowsTheThreadsAndTheQueueDepthOfTheInstancesOwnServer(): void
    {
        $dashboardPort = new Dotenv()->parse((string) file_get_contents(self::ROOT.'/.env.test'))['DASHBOARD_PORT'] ?? null;
        self::assertIsString($dashboardPort);
        $request = <<<'PHP_WRAP'
            $context = stream_context_create(["http" => ["header" => "Host: ".$argv[1], "ignore_errors" => true]]);
            $body = (string) file_get_contents(getenv("INTAKT_INSTANCE_URL")."/", false, $context);
            foreach (["Threads busy", "Queue depth"] as $label) { echo $label, ": ", str_contains($body, "<dt>".$label."</dt>") ? "shown" : "missing", "\n"; }
            PHP_WRAP;
        // Migrated first, because the board reads the ledger and bin/instance starts on an empty database.
        $command = ['sh', '-c', '"$0" bin/console --no-interaction --quiet doctrine:migrations:migrate && "$0" -r "$1" "$2"', PHP_BINARY, $request, 'localhost:'.$dashboardPort];

        $run = $this->runInstance(self::ROOT, $command, $this->environmentWithFrankenphp());

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame("Threads busy: shown\nQueue depth: shown\n", $run['output']);
    }

    public function testInWorkerModeFrankenphpRunsThreePhpThreadsWhateverTheNumberOfCpus(): void
    {
        // The count, not a boot under an address-space limit, because the memory a boot needs also depends on how FrankenPHP and its extensions are linked, so no one limit holds on every image.
        $command = <<<'PHP_WRAP'
            $states = json_decode((string) file_get_contents(getenv("INTAKT_INSTANCE_ADMIN_URL")."/frankenphp/threads"), true)["ThreadDebugStates"] ?? [];
            echo "PHP threads: ", count($states), "\n";
            PHP_WRAP;

        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', $command], $this->environmentWithFrankenphp());

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame("PHP threads: 3\n", $run['output']);
    }

    public function testAFrankenphpThatRunsOutOfMemoryReportsTheLastLineOfItsLogAsTheReason(): void
    {
        $stubs = $this->scratch.'/stubs';
        mkdir($stubs);
        file_put_contents($stubs.'/frankenphp', "#!/bin/sh\necho '{\"level\":\"info\",\"msg\":\"using config from file\"}' >&2\necho 'Out of memory' >&2\necho >&2\nexit 1\n");
        chmod($stubs.'/frankenphp', 0o755);

        $run = $this->runInstance(self::ROOT, environment: ['PATH' => $stubs.':'.$this->pathWithoutFrankenphp()]);

        self::assertSame('the server exited: Out of memory', $this->decoded($run['status'])['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testAFrankenphpThatExitsWithoutAWordReportsAnEmptyServerLog(): void
    {
        $stubs = $this->scratch.'/stubs';
        mkdir($stubs);
        file_put_contents($stubs.'/frankenphp', "#!/bin/sh\nexit 1\n");
        chmod($stubs.'/frankenphp', 0o755);

        $run = $this->runInstance(self::ROOT, environment: ['PATH' => $stubs.':'.$this->pathWithoutFrankenphp()]);

        self::assertSame('the server exited: the server log is empty', $this->decoded($run['status'])['reason']);
    }

    public function testInWorkerModeABrokenKernelConfigReportsFailedWithTheErrorAndExitsNonZero(): void
    {
        $project = $this->copyOfTheProject();
        file_put_contents($project.'/config/packages/broken.yaml', "framework:\n    no_such_option: true\n");

        $run = $this->runInstance($project, environment: $this->environmentWithFrankenphp());

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('"mode":"worker"', $run['status']);
        self::assertStringContainsString('no_such_option', $status['reason']);
        self::assertSame(1, $run['exit']);
    }

    public function testAFrankenphpInTheCallersDirectoryIsFoundThroughAnEmptyPathEntry(): void
    {
        $frankenphp = new ExecutableFinder()->find('frankenphp');
        self::assertNotNull($frankenphp, 'frankenphp must be on the PATH, as in the image and the build sandbox.');
        $caller = $this->scratch.'/caller';
        mkdir($caller);
        symlink($frankenphp, $caller.'/frankenphp');

        $run = $this->runInstanceFrom($caller, ['PATH' => ':'.$this->pathWithoutFrankenphp()]);

        self::assertSame('{"status":"ready","mode":"worker"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    public function testAFrankenphpThatFailsToStartReportsFailedWithItsReasonAndLeavesNothing(): void
    {
        $stubs = $this->scratch.'/stubs';
        mkdir($stubs);
        file_put_contents($stubs.'/frankenphp', "#!/bin/sh\necho 'Error: loading initial config: listen tcp 127.0.0.1:1: bind: address already in use' >&2\nexit 1\n");
        chmod($stubs.'/frankenphp', 0o755);

        $run = $this->runInstance(self::ROOT, environment: ['PATH' => $stubs.':'.$this->pathWithoutFrankenphp()]);

        $status = json_decode($run['status'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertSame(['status', 'mode', 'reason'], array_keys($status));
        self::assertSame(['failed', 'worker'], [$status['status'], $status['mode']]);
        self::assertSame('the server exited: Error: loading initial config: listen tcp 127.0.0.1:1: bind: address already in use', $status['reason']);
        self::assertSame(1, $run['exit']);
        self::assertSame([], $this->entriesOf($this->scratch.'/tmp'));
        self::assertSame([], $this->processesNaming($this->scratch.'/tmp'));
    }

    public function testAnAnswerOnTheChosenPortBeforeFrankenphpServesDoesNotMakeTheInstanceReady(): void
    {
        // The stub answers one request on the port like an unrelated listener would, then fails to bind as the real FrankenPHP does behind it.
        $stubs = $this->scratch.'/stubs';
        mkdir($stubs);
        file_put_contents($stubs.'/frankenphp', '#!'.PHP_BINARY."\n".<<<'PHP'
            <?php
            preg_match('~http://:(\d+)~', (string) file_get_contents($argv[array_search('--config', $argv, true) + 1]), $match);
            $listener = stream_socket_server('tcp://127.0.0.1:'.$match[1]);
            $connection = stream_socket_accept($listener, 3);
            if (false !== $connection) {
                fread($connection, 8192);
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                fclose($connection);
            }
            fwrite(STDERR, 'Error: loading initial config: listen tcp 127.0.0.1:'.$match[1].": bind: address already in use\n");
            exit(1);
            PHP);
        chmod($stubs.'/frankenphp', 0o755);

        $run = $this->runInstance(self::ROOT, [PHP_BINARY, '-r', 'echo "command ran\n";'], ['PATH' => $stubs.':'.$this->pathWithoutFrankenphp()]);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertMatchesRegularExpression('/^the server exited: Error: loading initial config: listen tcp 127\.0\.0\.1:\d+: bind: address already in use$/', $status['reason']);
        self::assertStringNotContainsString('command ran', $run['output']);
        self::assertSame(1, $run['exit']);
    }

    /**
     * @param array<string, string|false> $environment
     * @param list<string>                $command
     *
     * @return array{status: string, exit: int, output: string, errors: string}
     */
    private function runInstanceFrom(string $directory, array $environment, array $command = []): array
    {
        $statusFile = $this->scratch.'/status';
        $arguments = [] === $command ? [] : ['--', ...$command];
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, self::ROOT.'/bin/instance', ...$arguments])).' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($shell, $directory, [...$this->environment(), ...$environment]);
        $process->run();

        return [
            'status' => trim((string) file_get_contents($statusFile)),
            'exit' => (int) $process->getExitCode(),
            'output' => $process->getOutput(),
            'errors' => $process->getErrorOutput(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environmentWithFrankenphp(): array
    {
        $path = (string) getenv('PATH');
        self::assertNotNull(new ExecutableFinder()->find('frankenphp'), 'frankenphp must be on the PATH, as in the image and the build sandbox.');

        return ['PATH' => $path];
    }

    /**
     * @return list<string>
     */
    private function processesNaming(string $path): array
    {
        return array_values(array_filter(
            $this->matching('/proc/[0-9]*'),
            static fn (string $directory): bool => str_contains((string) @file_get_contents($directory.'/cmdline'), $path),
        ));
    }
}
