<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class TestsGate implements RunsTheChangesCode
{
    private const string CONFIGURATION = 'phpunit.dist.xml';
    private const string GATES_CONFIGURATION = 'phpunit.gates.xml';
    private const string SKIPPED_GATES_PASS = 'gates pass skipped, the change leaves the gate code, the gate tests and their inputs unchanged';
    private const array FAIL_CLOSED = [
        '--fail-on-empty-test-suite', '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky',
        '--fail-on-warning', '--fail-on-notice', '--fail-on-phpunit-notice', '--fail-on-deprecation', '--fail-on-phpunit-deprecation',
    ];
    private const int PASS_TIMEOUT_SECONDS = 3600;
    // Because the slowest test file takes under a minute, ten minutes on one file means it hangs.
    private const int FILE_TIMEOUT_SECONDS = 600;

    public function __construct(
        private float $fileTimeoutSeconds = self::FILE_TIMEOUT_SECONDS,
        private float $passTimeoutSeconds = self::PASS_TIMEOUT_SECONDS,
    ) {
    }

    public function name(): string
    {
        return 'tests';
    }

    public function run(Workspace $workspace): GateResult
    {
        $unready = $this->whyNotReady($workspace);

        if (null !== $unready) {
            return GateResult::failedBecause($this->name(), $unready);
        }

        try {
            $passes = $this->passes($workspace);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $count = TestWorkerCount::chosen($this->variable(TestWorkerCount::VARIABLE), Processors::status(), Processors::cpuMax());

        if (null === $count) {
            return GateResult::failedBecause($this->name(), TestWorkerCount::VARIABLE.' names no number of test workers from 1 to '.TestWorkerCount::most());
        }

        // Because a single worker keeps one process for each pass, and the passes still run side by side as before.
        $slots = max($count, count($passes));
        $ports = TestPorts::forWorkers($slots, $this->variable(TestWorker::PORTS_VARIABLE));

        if (!$ports instanceof TestPorts) {
            return GateResult::failedBecause($this->name(), TestWorker::PORTS_VARIABLE.' names no range of ports that '.$slots.' test workers can share');
        }

        $scratch = $this->scratchDirectory();

        if (null === $scratch) {
            return GateResult::failedBecause($this->name(), 'cannot create a scratch directory for the test passes');
        }

        try {
            return $this->resultOf($workspace, new TestWorkers($scratch, $ports, $count), $passes);
        } finally {
            $this->remove($scratch);
        }
    }

    private function whyNotReady(Workspace $workspace): ?string
    {
        if (!$workspace->hasTool('phpunit')) {
            return 'missing tool phpunit';
        }

        foreach ([self::CONFIGURATION, self::GATES_CONFIGURATION] as $configuration) {
            if (!is_file($workspace->root.'/'.$configuration)) {
                return 'no '.$configuration.' in the tree';
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function passes(Workspace $workspace): array
    {
        // Because unchanged gate code with an unchanged lock file gives the same result, and every push to main runs both passes.
        if (!$workspace->changesGateTests()) {
            return ['sqlite' => self::CONFIGURATION];
        }

        return ['sqlite' => self::CONFIGURATION, 'gates' => self::GATES_CONFIGURATION];
    }

    private function variable(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function scratchDirectory(): ?string
    {
        $directory = sys_get_temp_dir().'/intakt-tests-'.bin2hex(random_bytes(8));

        return mkdir($directory, 0o700) ? $directory : null;
    }

    /**
     * @param array<string, string> $passes
     */
    private function resultOf(Workspace $workspace, TestWorkers $workers, array $passes): GateResult
    {
        $result = GateResult::withFindings($this->name(), $this->findings($workspace, $workers, $passes))
            ->withSlowest($this->recordedDurations($workspace, $workers));

        return isset($passes['gates']) ? $result : $result->noted(self::SKIPPED_GATES_PASS);
    }

    /**
     * @param array<string, string> $passes
     *
     * @return list<string>
     */
    private function findings(Workspace $workspace, TestWorkers $workers, array $passes): array
    {
        $numbered = [];
        $alone = [];
        $prepared = [];

        foreach (array_keys($passes) as $index => $pass) {
            $numbered[] = $workers->numbered($pass, $passes[$pass], $index);
            $alone[] = $workers->serial($pass, $passes[$pass]);
            $prepared = [...$prepared, ...$workers->allOf($pass, $passes[$pass])];
        }

        foreach ([...$prepared, ...$alone] as $worker) {
            if (!$worker->prepared()) {
                return [$worker->run.': cannot create the scratch directory of the run in '.$workers->scratch];
            }
        }

        $listings = $this->concurrently($workspace, [...$numbered, ...$alone], static fn (TestWorker $worker): array => ['--list-test-files', '--colors=never', '--log-events-text='.$worker->listingEvents()]);
        $unlisted = $this->unlisted([...$numbered, ...$alone], $listings);

        if ([] !== $unlisted) {
            return $unlisted;
        }

        [$concurrent, $serial] = $this->withTests($numbered, $alone, $listings);
        $findings = $this->findingsOf($this->queued($workspace, $workers, $this->testJobs($workspace, $workers)->queue($concurrent, $listings)));

        foreach ($serial as $worker) {
            $findings = [...$findings, ...$this->findingsOf($this->concurrently($workspace, [$worker], $this->testRun(...)))];
        }

        return $findings;
    }

    /**
     * @param list<TestWorker>                  $workers
     * @param Closure(TestWorker): list<string> $flags
     *
     * @return array<string, ProcessResult>
     */
    private function concurrently(Workspace $workspace, array $workers, Closure $flags): array
    {
        $commands = [];

        foreach ($workers as $worker) {
            $commands[$worker->run] = [$this->command($workspace, $worker, $flags($worker)), $worker->environment()];
        }

        return $workspace->commandsConcurrently($commands);
    }

    /**
     * @param list<TestWorker>             $workers
     * @param array<string, ProcessResult> $listings
     *
     * @return list<string>
     */
    private function unlisted(array $workers, array $listings): array
    {
        $findings = [];

        foreach ($workers as $worker) {
            $failed = PhpunitReport::findings($worker->run, $listings[$worker->run]);
            $findings = [...$findings, ...([] === $failed ? PhpunitReport::listingDiagnostics($worker->run, $worker->listingEvents()) : $failed)];
        }

        return $findings;
    }

    /**
     * @param list<TestWorker>             $numbered
     * @param list<TestWorker>             $alone
     * @param array<string, ProcessResult> $listings
     *
     * @return array{list<TestWorker>, list<TestWorker>}
     */
    private function withTests(array $numbered, array $alone, array $listings): array
    {
        $listsATest = static fn (TestWorker $worker): bool => 1 === preg_match('/^ - /m', $listings[$worker->run]->output);
        $concurrent = [];
        $serial = array_values(array_filter($alone, $listsATest));

        foreach ($numbered as $index => $worker) {
            // Because a configuration whose tests are all serial leaves the workers nothing, while one without any test must still fail on the empty test suite.
            if ($listsATest($worker) || !$listsATest($alone[$index])) {
                $concurrent[] = $worker;
            }
        }

        return [$concurrent, $serial];
    }

    private function testJobs(Workspace $workspace, TestWorkers $workers): TestJobs
    {
        $recorded = new TestDurations($workspace->root)->recorded(GatesCache::directory());

        return new TestJobs($workers, $workspace->root, fn (TestWorker $worker): array => $this->command($workspace, $worker, $this->testRun($worker)), $recorded);
    }

    /**
     * @param array<string, Closure(int): array{list<string>, array<string, string>}> $jobs
     *
     * @return array<string, ProcessResult>
     */
    private function queued(Workspace $workspace, TestWorkers $workers, array $jobs): array
    {
        $seconds = 1 === $workers->count ? $this->passTimeoutSeconds : $this->fileTimeoutSeconds;

        return new ProcessQueue($seconds, $this->passTimeoutSeconds)->run($jobs, $workers->slots(), $workspace->root);
    }

    /**
     * @param list<string> $flags
     *
     * @return list<string>
     */
    private function command(Workspace $workspace, TestWorker $worker, array $flags): array
    {
        return [$workspace->tool('phpunit'), '--configuration='.$workspace->root.'/'.$worker->configuration, ...$flags, ...$worker->arguments()];
    }

    /**
     * @param array<string, ProcessResult> $results
     *
     * @return list<string>
     */
    private function findingsOf(array $results): array
    {
        $findings = [];

        foreach ($results as $run => $result) {
            $findings = [...$findings, ...PhpunitReport::findings($run, $result)];
        }

        return $findings;
    }

    /**
     * @return array<string, float>
     */
    private function recordedDurations(Workspace $workspace, TestWorkers $workers): array
    {
        $durations = new TestDurations($workspace->root);
        $seconds = $durations->read($workers->junitReports());
        $durations->write($seconds, GatesCache::directory());

        return $durations->slowest($seconds);
    }

    private function remove(string $directory): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } elseif ($entry instanceof SplFileInfo) {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }

    /**
     * @return list<string>
     */
    private function testRun(TestWorker $worker): array
    {
        return [
            ...self::FAIL_CLOSED,
            '--display-phpunit-notices', '--display-phpunit-deprecations', '--do-not-record-test-run-history', '--log-junit='.$worker->junitReport(), '--colors=never', '--no-progress',
        ];
    }
}
