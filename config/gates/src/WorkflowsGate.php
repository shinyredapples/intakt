<?php

declare(strict_types=1);

namespace Intakt\Gates;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class WorkflowsGate implements Gate
{
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

    // Because the gate must give the same answer without network, without a token and without a file that disables audits.
    private function audit(Workspace $workspace): GateResult
    {
        $result = $workspace->command(
            ['zizmor', '--offline', '--no-config', '--no-progress', '--format=github', '.github'],
            ['GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'ZIZMOR_GITHUB_TOKEN' => false],
        );

        preg_match_all(self::FINDING, $result->output, $matches, PREG_SET_ORDER);

        if ([] !== $matches) {
            return GateResult::withFindings($this->name(), array_map(static fn (array $match): string => vsprintf('%s:%s: %s: %s', array_slice($match, 1)), $matches));
        }

        return $result->succeeded() ? GateResult::passed($this->name()) : GateResult::failedBecause($this->name(), 'zizmor failed: '.$result->firstErrorLine());
    }

    /**
     * @return list<string>
     */
    private function bareIgnores(string $root): array
    {
        $findings = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/.github', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $lines = in_array($file->getExtension(), ['yml', 'yaml'], true) ? file($file->getPathname()) : [];

            foreach (preg_grep(self::BARE_IGNORE, $lines ?: []) ?: [] as $index => $line) {
                $findings[] = substr($file->getPathname(), strlen($root) + 1).':'.($index + 1).': zizmor ignore without a reason';
            }
        }

        return $findings;
    }
}
