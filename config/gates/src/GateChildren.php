<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class GateChildren
{
    private const string ENTRY = 'config/gates/run-gate.php';

    public function __construct(
        private string $home,
        private string $root,
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
        return array_map(fn (string $gate): GateReport => $this->reportOf($gate, $arguments), $gates);
    }

    /**
     * @param list<string> $arguments
     */
    private function reportOf(string $gate, array $arguments): GateReport
    {
        // Because the preflight finds `setsid` and `sh` from the workspace root, the child's launcher is searched from there too.
        $child = $this->runner->runToItsEnd([PHP_BINARY, $this->home.'/'.self::ENTRY, $gate, ...$arguments], $this->directory(), toolsFrom: $this->root);
        fwrite(STDERR, $child->errors);

        return GateReport::fromChild($gate, $child);
    }

    // Because a gate ran in bin/check's own directory before it had a child process, the child starts there too.
    private function directory(): string
    {
        $directory = getcwd();

        return is_string($directory) ? $directory : $this->home;
    }
}
