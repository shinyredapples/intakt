<?php

declare(strict_types=1);

namespace Intakt\Gates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestsGate implements Gate
{
    private const string SERIAL_GROUP = 'serial';
    private const string WORKERS = '--exclude-group=';
    private const string ALONE = '--group=';
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

        if (null === $ports) {
            return GateResult::failedBecause($this->name(), TestWorker::PORTS_VARIABLE.' names no range of ports that '.count($passes).' test workers can share');
        }

        $scratch = $this->scratchDirectory();

        if (null === $scratch) {
            return GateResult::failedBecause($this->name(), 'cannot create a scratch directory for the test passes');
        }

        try {
            return $this->resultOf($workspace, $scratch, $passes, $ports);
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
    private function resultOf(Workspace $workspace, string $scratch, array $passes, TestPorts $ports): GateResult
    {
        $result = GateResult::withFindings($this->name(), $this->findings($workspace, $scratch, $passes, $ports))
            ->withSlowest($this->recordedDurations($workspace, $scratch));

        return isset($passes['gates']) ? $result : $result->noted(self::SKIPPED_GATES_PASS);
    }

    /**
     * @param array<string, string> $passes
     *
     * @return list<string>
     */
    private function findings(Workspace $workspace, string $scratch, array $passes, TestPorts $ports): array
    {
        $commands = [];
        $alone = [];
        $findings = [];

        foreach (array_keys($passes) as $index => $pass) {
            $workers = [self::WORKERS => TestWorker::numbered($scratch, $pass, $index, $ports), self::ALONE => TestWorker::serial($scratch, $pass, $ports)];

            if (!$workers[self::WORKERS]->prepared() || !$workers[self::ALONE]->prepared()) {
                return [$pass.': cannot create the scratch directory of the pass in '.$scratch];
            }

            $listed = [];

            foreach ($workers as $part => $worker) {
                $listing = $workspace->command([$workspace->tool('phpunit'), '--configuration='.$workspace->root.'/'.$passes[$pass], '--list-tests', '--colors=never', $part.self::SERIAL_GROUP], $worker->environment());

                if (!$listing->succeeded()) {
                    $findings = [...$findings, ...PhpunitReport::findings($worker->run, $listing)];

                    continue 2;
                }

                $listed[$part] = 1 === preg_match('/^ - /m', $listing->output);
            }

            // Because a configuration whose tests are all serial leaves the workers nothing, while one without any test must still fail on the empty test suite.
            if ($listed[self::WORKERS] || !$listed[self::ALONE]) {
                $commands[$pass] = [[...$this->phpunit($workspace, $passes[$pass], $workers[self::WORKERS]->junitReport()), self::WORKERS.self::SERIAL_GROUP], $workers[self::WORKERS]->environment()];
            }

            if ($listed[self::ALONE]) {
                $alone[$pass] = $workers[self::ALONE];
            }
        }

        foreach ($workspace->commandsConcurrently($commands) as $pass => $result) {
            $findings = [...$findings, ...PhpunitReport::findings($pass, $result)];
        }

        foreach ($alone as $pass => $worker) {
            $result = $workspace->command([...$this->phpunit($workspace, $passes[$pass], $worker->junitReport()), self::ALONE.self::SERIAL_GROUP], $worker->environment());
            $findings = [...$findings, ...PhpunitReport::findings($worker->run, $result)];
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
    private function recordedDurations(Workspace $workspace, string $scratch): array
    {
        $durations = new TestDurations($workspace->root);
        $reports = glob($scratch.'/*/*/junit.xml');
        $seconds = $durations->read(false === $reports ? [] : $reports);
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
    private function phpunit(Workspace $workspace, string $configuration, string $junitReport): array
    {
        return [
            $workspace->tool('phpunit'),
            '--configuration='.$workspace->root.'/'.$configuration,
            ...self::FAIL_CLOSED,
            '--display-phpunit-notices', '--display-phpunit-deprecations', '--do-not-record-test-run-history', '--log-junit='.$junitReport, '--colors=never', '--no-progress',
        ];
    }
}
