<?php

declare(strict_types=1);

namespace Intakt\Gates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestsGate implements Gate
{
    private const string GATES_CACHE_VARIABLE = 'INTAKT_GATES_CACHE';
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

        $scratch = $this->scratchDirectory();

        if (null === $scratch) {
            return GateResult::failedBecause($this->name(), 'cannot create a scratch directory for the test passes');
        }

        try {
            return $this->resultOf($workspace, $scratch, $passes);
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
    private function resultOf(Workspace $workspace, string $scratch, array $passes): GateResult
    {
        $result = GateResult::withFindings($this->name(), $this->findings($workspace, $scratch, $passes))
            ->withSlowest($this->recordedDurations($workspace, $scratch, array_keys($passes)));

        return isset($passes['gates']) ? $result : $result->noted(self::SKIPPED_GATES_PASS);
    }

    /**
     * @param array<string, string> $passes
     *
     * @return list<string>
     */
    private function findings(Workspace $workspace, string $scratch, array $passes): array
    {
        $commands = [];

        foreach ($passes as $pass => $configuration) {
            if (!mkdir($scratch.'/'.$pass, 0o700)) {
                return [$pass.': cannot create the scratch directory of the pass in '.$scratch];
            }

            $commands[$pass] = [$this->phpunit($workspace, $configuration, $this->junitReport($scratch, $pass)), $this->isolated($scratch, $pass)];
        }

        $findings = [];

        foreach ($workspace->commandsConcurrently($commands) as $pass => $result) {
            $findings = [...$findings, ...PhpunitReport::findings($pass, $result)];
        }

        return $findings;
    }

    /**
     * @param list<string> $passes
     *
     * @return array<string, float>
     */
    private function recordedDurations(Workspace $workspace, string $scratch, array $passes): array
    {
        $durations = new TestDurations($workspace->root);
        $seconds = $durations->read(array_map(fn (string $pass): string => $this->junitReport($scratch, $pass), $passes));
        $durations->write($seconds, $this->gatesCache());

        return $durations->slowest($seconds);
    }

    private function junitReport(string $scratch, string $pass): string
    {
        return $scratch.'/'.$pass.'/junit.xml';
    }

    private function gatesCache(): string
    {
        $base = getenv(self::GATES_CACHE_VARIABLE);

        return is_string($base) && '' !== $base ? $base : sys_get_temp_dir().'/intakt-gates';
    }

    /**
     * @return array<string, string>
     */
    private function isolated(string $scratch, string $pass): array
    {
        $directory = $scratch.'/'.$pass;

        return [
            'DATABASE_URL' => 'sqlite:///'.$directory.'/database.sqlite',
            'APP_CACHE_DIR' => $directory.'/cache',
            'APP_LOG_DIR' => $directory.'/log',
            self::GATES_CACHE_VARIABLE => $directory.'/tools',
        ];
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
