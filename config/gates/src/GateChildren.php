<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateChildren
{
    public const string FULL_RESULT_VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    private const string ENTRY = 'config/gates/run-gate.php';

    private const string SCRATCH_PREFIX = 'intakt-gate-results';

    private const int POLL_MICROSECONDS = 10000;

    /**
     * @param list<string> $runningTheChangesCode
     */
    public function __construct(
        private string $home,
        private string $root,
        private GateSlots $slots,
        private array $runningTheChangesCode,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /**
     * @param list<string> $gates
     * @param list<string> $arguments
     *
     * @return list<GateReport>
     */
    // Because the folder holds every child's whole result, it goes whatever ends the run, also when starting a child throws.
    public function reportsOf(array $gates, array $arguments, ?ScratchDirectory $scratch): array
    {
        try {
            $results = $this->resultsOf($this->slots->launchOrder($gates, $this->runningTheChangesCode), $this->childrenOf($gates, $arguments, $scratch), $scratch);

            return array_map(static fn (string $gate): GateReport => self::keptIn($scratch, GateReport::fromChild($gate, $results[$gate])), $gates);
        } finally {
            $scratch?->discard();
        }
    }

    // Because gates scan every file under the checked tree, a scratch folder there would put one gate's whole result into the scan of the next.
    public function scratchOutsideTheTree(): ?ScratchDirectory
    {
        $scratch = ScratchDirectory::create(self::SCRATCH_PREFIX);
        $tree = realpath($this->root);

        if (!$scratch instanceof ScratchDirectory || false === $tree || !str_starts_with((string) realpath($scratch->path), rtrim($tree, '/').'/')) {
            return $scratch;
        }

        $scratch->discard();

        return null;
    }

    /**
     * @param list<string> $gates
     * @param list<string> $arguments
     *
     * @return array<string, ChildCommand>
     */
    private function childrenOf(array $gates, array $arguments, ?ScratchDirectory $scratch): array
    {
        $children = [];

        foreach ($gates as $gate) {
            // Because a child inherits bin/check's environment, a hand-off set outside would otherwise still name a file when there is no scratch folder.
            $handOff = [self::FULL_RESULT_VARIABLE => $scratch instanceof ScratchDirectory ? self::wholeResultFile($scratch, $gate) : false];
            $children[$gate] = new ChildCommand([PHP_BINARY, $this->home.'/'.self::ENTRY, $gate, ...$arguments], $this->directory(), [...LowPriority::environmentFor($gate, $this->runningTheChangesCode, $this->slots), ...$handOff]);
        }

        return $children;
    }

    private static function wholeResultFile(ScratchDirectory $scratch, string $gate): string
    {
        return $scratch->path.'/'.$gate.'.json';
    }

    /**
     * @param list<string>                $waiting
     * @param array<string, ChildCommand> $children
     *
     * @return array<string, ProcessResult>
     */
    private function resultsOf(array $waiting, array $children, ?ScratchDirectory $scratch): array
    {
        $running = [];
        $results = [];
        // Because one Ctrl-C or kill meant for bin/check must reach every child running at that moment, and bin/check then dies of that signal before any finally runs.
        $forwarding = StopSignalForwarding::through(static function () use (&$running, $scratch): void {
            self::stopAll($running);
            $scratch?->discard();
        });

        while ([] !== $waiting || [] !== $running) {
            [$waiting, $started] = $this->launched($waiting, $running, $children);
            $running += $started;
            $ended = $this->ended($running);
            $running = array_diff_key($running, $ended);
            $results += $ended;

            usleep(self::POLL_MICROSECONDS);
        }

        $forwarding->end();

        return $results;
    }

    private static function keptIn(?ScratchDirectory $scratch, GateReport $report): GateReport
    {
        $file = $scratch instanceof ScratchDirectory ? self::wholeResultFile($scratch, $report->gate) : null;

        if (null === $file || !is_file($file) || !is_readable($file)) {
            return $report;
        }

        $kept = file_get_contents($file);

        return false === $kept ? $report : $report->keeping($kept);
    }

    // Because each group gets its grace in turn, all of them are asked first, so none waits for the grace of another.
    /**
     * @param array<string, RunningChild|ProcessResult> $running
     */
    private static function stopAll(array $running): void
    {
        $groups = array_map(static fn (RunningChild $child): ProcessGroup => $child->group, array_filter($running, static fn (RunningChild|ProcessResult $child): bool => $child instanceof RunningChild));

        foreach ($groups as $group) {
            $group->askToStop();
        }

        foreach ($groups as $group) {
            $group->stop();
        }
    }

    /**
     * @param list<string>                              $waiting
     * @param array<string, RunningChild|ProcessResult> $running
     * @param array<string, ChildCommand>               $children
     *
     * @return array{list<string>, array<string, RunningChild|ProcessResult>}
     */
    private function launched(array $waiting, array $running, array $children): array
    {
        $started = [];

        while ([] !== $waiting && $this->mayStart($waiting, [...array_keys($running), ...array_keys($started)])) {
            $gate = array_shift($waiting);
            $started[$gate] = $this->started($children[$gate]);
        }

        return [$waiting, $started];
    }

    /**
     * @param non-empty-list<string> $waiting
     * @param list<string>           $running
     */
    private function mayStart(array $waiting, array $running): bool
    {
        $staticGatesLeft = [] !== array_diff([...$waiting, ...$running], $this->runningTheChangesCode);

        return $this->slots->allow($running, $waiting[0]) && (!$staticGatesLeft || !in_array($waiting[0], $this->runningTheChangesCode, true));
    }

    private function started(ChildCommand $child): RunningChild|ProcessResult
    {
        // Because the preflight finds `setsid` and `sh` from the workspace root, the child's launcher is searched from there too.
        return $this->runner->startToItsEndWithToolsFrom($this->root, $child);
    }

    /**
     * @param array<string, RunningChild|ProcessResult> $running
     *
     * @return array<string, ProcessResult>
     */
    private function ended(array $running): array
    {
        $ended = [];

        foreach ($running as $gate => $child) {
            $result = $child instanceof ProcessResult ? $child : $this->runner->endOf($child);

            if ($result instanceof ProcessResult) {
                fwrite(STDERR, $result->errors);
                $ended[$gate] = $result;
            }
        }

        return $ended;
    }

    // Because a gate ran in bin/check's own directory before it had a child process, the child starts there too.
    private function directory(): string
    {
        $directory = getcwd();

        return is_string($directory) ? $directory : $this->home;
    }
}
