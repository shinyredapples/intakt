<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateChildren
{
    private const string ENTRY = 'config/gates/run-gate.php';

    private const int POLL_MICROSECONDS = 10000;

    public function __construct(
        private string $home,
        private string $root,
        private GateSlots $slots,
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /**
     * @param list<string> $gates
     * @param list<string> $arguments
     *
     * @return list<GateReport>
     */
    public function reportsOf(array $gates, array $arguments): array
    {
        $results = $this->resultsOf($this->slots->launchOrder($gates), $this->childrenOf($gates, $arguments));

        return array_map(static fn (string $gate): GateReport => GateReport::fromChild($gate, $results[$gate]), $gates);
    }

    /**
     * @param list<string> $gates
     * @param list<string> $arguments
     *
     * @return array<string, ChildCommand>
     */
    private function childrenOf(array $gates, array $arguments): array
    {
        $children = [];

        foreach ($gates as $gate) {
            $children[$gate] = new ChildCommand([PHP_BINARY, $this->home.'/'.self::ENTRY, $gate, ...$arguments], $this->directory(), LowPriority::environmentFor($gate, $gates, $this->slots));
        }

        return $children;
    }

    /**
     * @param list<string>                $waiting
     * @param array<string, ChildCommand> $children
     *
     * @return array<string, ProcessResult>
     */
    private function resultsOf(array $waiting, array $children): array
    {
        $running = [];
        $results = [];
        // Because one Ctrl-C or kill meant for bin/check must reach every child running at that moment.
        $forwarding = StopSignalForwarding::through(static function () use (&$running): void {
            self::stopAll($running);
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

        while ([] !== $waiting && $this->slots->allow([...array_keys($running), ...array_keys($started)], $waiting[0])) {
            $gate = array_shift($waiting);
            $started[$gate] = $this->started($children[$gate]);
        }

        return [$waiting, $started];
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

    // Because a gate ran in bin/check's own directory before it had a child process, the child starts there too.
    private function directory(): string
    {
        $directory = getcwd();

        return is_string($directory) ? $directory : $this->home;
    }
}
