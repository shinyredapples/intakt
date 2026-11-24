<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;

final readonly class GateChildren
{
    public const string FULL_RESULT_VARIABLE = 'INTAKT_GATE_FULL_RESULT';

    private const string ENTRY = 'config/gates/run-gate.php';

    private const string SCRATCH_PREFIX = 'intakt-gate-results';

    private const int POLL_MICROSECONDS = 10000;

    // Because no outside timing can put a stop between two starts of one launch round, a test needs to act right after each start; `afterEachStart` is that test seam only, not an extension point, and bin/check never passes it.
    /**
     * @param list<string>                              $runningTheChangesCode
     * @param Closure(RunningChild|ProcessResult): void $afterEachStart
     */
    public function __construct(
        private string $home,
        private string $root,
        private GateSlots $slots,
        private array $runningTheChangesCode,
        private ProcessRunner $runner = new ProcessRunner(),
        private Closure $afterEachStart = static function (RunningChild|ProcessResult $child): void {
        },
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
     * @param list<string>                $order
     * @param array<string, ChildCommand> $children
     *
     * @return array<string, ProcessResult>
     */
    private function resultsOf(array $order, array $children, ?ScratchDirectory $scratch): array
    {
        $waiting = array_combine($order, array_map(static fn (string $gate): ChildCommand => $children[$gate], $order));
        $running = [];
        $results = [];
        // Because one Ctrl-C or kill meant for bin/check must reach every child running at that moment, and bin/check then dies of that signal before any finally runs.
        $forwarding = StopSignalForwarding::through(static function () use (&$running, $scratch): void {
            self::stopAll($running);
            $scratch?->discard();
        });

        while ([] !== $waiting || [] !== $running) {
            $waiting = $this->launched($waiting, $running, $forwarding);
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

    // Because the stop handler reads the same set, each child joins it the moment it starts, not once the whole round has started, and a stop waits until it has joined.
    /**
     * @param array<string, ChildCommand>               $waiting
     * @param array<string, RunningChild|ProcessResult> $running
     *
     * @return array<string, ChildCommand>
     */
    private function launched(array $waiting, array &$running, StopSignalForwarding $forwarding): array
    {
        while ([] !== $waiting && $this->mayStart(array_keys($waiting), array_keys($running))) {
            $gate = array_key_first($waiting);
            $child = $waiting[$gate];
            unset($waiting[$gate]);
            $forwarding->deferredAcross(function () use (&$running, $gate, $child): void {
                $running[$gate] = $this->started($child);
            });
            ($this->afterEachStart)($running[$gate]);
        }

        return $waiting;
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
