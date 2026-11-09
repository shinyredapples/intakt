<?php

declare(strict_types=1);

namespace Intakt\Gates;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class WorkflowsGate implements Gate
{
    private const string REASON = '/^(?:\s*1|error): (.+)$/m';
    private const string VERSION = 'zizmor 1.32.0';
    private const string BARE_IGNORE = '/#.*zizmor:\s*ignore\[[^\]]*\][^\pL]*$/u';
    private const string FINDING = '/^::\w+ file=([^,]+),line=(\d+),title=([^:]+)::(?:\S+:\d+: )?(.*)$/m';

    public function name(): string
    {
        return 'workflows';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!is_dir($workspace->root.'/.github')) {
            return GateResult::passedBecause($this->name(), 'no .github folder');
        }

        $bareIgnores = $this->bareIgnores($workspace->root);

        if ([] !== $bareIgnores) {
            return GateResult::withFindings($this->name(), $bareIgnores);
        }

        if (self::VERSION !== trim($workspace->command(['zizmor', '--version'])->output)) {
            return GateResult::failedBecause($this->name(), self::VERSION.' is required, put it first on the path, see '.RequiredTools::REQUIREMENTS);
        }

        return $this->audit($workspace);
    }

    // Because the gate must give the same answer without network, without a token and without a file that disables audits, and fail on a file it cannot load.
    private function audit(Workspace $workspace): GateResult
    {
        $result = $workspace->command(
            ['zizmor', '--offline', '--no-config', '--no-progress', '--strict-collection', '--format=github', '.github'],
            ['GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'ZIZMOR_GITHUB_TOKEN' => false],
        );

        preg_match_all(self::FINDING, $result->output, $matches, PREG_SET_ORDER);

        if ([] !== $matches) {
            return GateResult::withFindings($this->name(), array_map(static fn (array $match): string => vsprintf('%s:%s: %s: %s', array_slice($match, 1)), $matches));
        }

        return $result->succeeded() ? GateResult::passed($this->name()) : GateResult::failedBecause($this->name(), 'zizmor failed: '.$this->reasonOf($result));
    }

    // Because zizmor prints its version banner before the reason it failed.
    private function reasonOf(ProcessResult $result): string
    {
        return 1 === preg_match(self::REASON, $result->errors."\n".$result->output, $reason) ? $reason[1] : $result->firstErrorLine();
    }

    /**
     * @return list<string>
     */
    private function bareIgnores(string $root): array
    {
        $findings = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/.github', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (in_array($file->getExtension(), ['yml', 'yaml'], true)) {
                array_push($findings, ...$this->bareIgnoresIn($file->getPathname(), substr($file->getPathname(), strlen($root) + 1)));
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function bareIgnoresIn(string $path, string $name): array
    {
        $lines = is_readable($path) ? file($path) : false;

        if (false === $lines) {
            return [$name.' cannot be read'];
        }

        $bare = array_filter($lines, static fn (string $line): bool => 1 === preg_match(self::BARE_IGNORE, $line));

        return array_map(static fn (int $index): string => $name.':'.($index + 1).': zizmor ignore without a reason', array_keys($bare));
    }
}
