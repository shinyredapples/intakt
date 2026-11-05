<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestsGate implements Gate
{
    private const string CONFIGURATION = 'phpunit.dist.xml';
    private const string GATES_CONFIGURATION = 'phpunit.gates.xml';
    private const string SKIPPED_GATES_PASS = 'gates pass skipped, the change leaves the gate code, the gate tests and their inputs unchanged';
    private const array FAIL_CLOSED = [
        '--fail-on-empty-test-suite', '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky',
        '--fail-on-warning', '--fail-on-notice', '--fail-on-phpunit-notice', '--fail-on-deprecation', '--fail-on-phpunit-deprecation',
    ];

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

        $ports = TestPorts::forWorkers(count($passes), $this->variable(TestWorker::PORTS_VARIABLE));

        if (!$ports instanceof TestPorts) {
            return GateResult::failedBecause($this->name(), TestWorker::PORTS_VARIABLE.' names no range of ports that '.count($passes).' test workers can share');
        }

        $scratch = $this->scratchDirectory();

        if (null === $scratch) {
            return GateResult::failedBecause($this->name(), 'cannot create a scratch directory for the test passes');
        }

        try {
            return $this->resultOf($workspace, new TestWorkers($scratch, $ports), $passes);
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

        foreach (array_keys($passes) as $index => $pass) {
            $numbered[] = $workers->numbered($pass, $passes[$pass], $index);
            $alone[] = $workers->serial($pass, $passes[$pass]);
        }

        foreach ([...$numbered, ...$alone] as $worker) {
            if (!$worker->prepared()) {
                return [$worker->run.': cannot create the scratch directory of the run in '.$workers->scratch];
            }
        }

        $listings = $this->concurrently($workspace, [...$numbered, ...$alone], static fn (): array => ['--list-tests', '--colors=never']);
        $unlisted = $this->findingsOf($listings);

        if ([] !== $unlisted) {
            return $unlisted;
        }

        [$concurrent, $serial] = $this->withTests($numbered, $alone, $listings);
        $findings = $this->findingsOf($this->concurrently($workspace, $concurrent, $this->testRun(...)));

        foreach ($serial as $worker) {
            $findings = [...$findings, ...$this->findingsOf($this->concurrently($workspace, [$worker], $this->testRun(...)))];
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
            $commands[$worker->run] = [[$workspace->tool('phpunit'), '--configuration='.$workspace->root.'/'.$worker->configuration, ...$flags($worker), $worker->selection], $worker->environment()];
        }

        return $workspace->commandsConcurrently($commands);
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

    private function variable(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @return array<string, float>
     */
    private function recordedDurations(Workspace $workspace, TestWorkers $workers): array
    {
        $durations = new TestDurations($workspace->root);
        $seconds = $durations->read($workers->junitReports());
        $durations->write($seconds, $this->gatesCache());

        return $durations->slowest($seconds);
    }

    private function gatesCache(): string
    {
        return $this->variable(TestWorker::GATES_CACHE_VARIABLE) ?? sys_get_temp_dir().'/intakt-gates';
    }

    private function scratchDirectory(): ?string
    {
        $directory = sys_get_temp_dir().'/intakt-tests-'.bin2hex(random_bytes(8));

        return mkdir($directory, 0o700) ? $directory : null;
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
