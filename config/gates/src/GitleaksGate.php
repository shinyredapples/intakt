<?php

declare(strict_types=1);

namespace Intakt\Gates;

abstract class GitleaksGate implements Gate
{
    protected const string INLINE_ALLOW = "gitleaks\x3aallow";
    protected const array UNSCANNED_FOLDERS = [':(glob)**/vendor/**', ':(glob)**/node_modules/**'];
    private const string IGNORE_FILE = '.gitleaksignore';
    public const string PINNED_VERSION = '8.30.1';

    final protected function scan(Workspace $workspace, ScanTarget $target, string $configPath): GateResult
    {
        $bypasses = $this->bypassesIn($workspace);

        if ([] !== $bypasses) {
            return GateResult::withFindings($this->name(), $bypasses);
        }

        $scanner = $this->pinnedScanner($workspace);

        if (null === $scanner) {
            return GateResult::failedBecause($this->name(), 'gitleaks '.self::PINNED_VERSION.' is required, set '.RequiredTools::SCANNER_ENVIRONMENT.' to it or put it first on the path, an override is checked too, see '.RequiredTools::REQUIREMENTS);
        }

        $reportPath = tempnam(sys_get_temp_dir(), 'gitleaks');

        if (false === $reportPath) {
            return GateResult::failedBecause($this->name(), 'cannot create a report file');
        }

        $result = $workspace->command([
            'env', '--chdir='.$target->source,
            $scanner, 'detect', '--no-banner', '--redact', '--report-format=json', '--report-path='.$reportPath,
            '--source=.', '--config='.$configPath, ...$target->options,
        ], new HardenedGitCall()->environment($target->source));

        $report = (string) file_get_contents($reportPath);
        unlink($reportPath);

        return $this->resultOf($result, $report, $target->source);
    }

    /**
     * @return list<string>
     */
    abstract protected function bypassesIn(Workspace $workspace): array;

    /**
     * @return list<string>
     */
    final protected function ignoreFileFindings(Workspace $workspace): array
    {
        return file_exists($workspace->root.'/'.self::IGNORE_FILE) ? [self::IGNORE_FILE.' hides findings from the scan, remove it'] : [];
    }

    /**
     * @return list<string>
     */
    final protected function lines(string $output): array
    {
        return array_values(array_filter(explode("\n", $output), static fn (string $line): bool => '' !== $line));
    }

    private function pinnedScanner(Workspace $workspace): ?string
    {
        $scanner = RequiredTools::scanner();
        $version = $workspace->command([$scanner, 'version']);

        return $version->succeeded() && self::PINNED_VERSION === ltrim(trim($version->output), 'v') ? $scanner : null;
    }

    private function resultOf(ProcessResult $result, string $report, string $root): GateResult
    {
        if ($result->succeeded()) {
            return GateResult::passed($this->name());
        }

        $findings = $this->findings($report, $root);

        return GateResult::withFindings($this->name(), [] === $findings ? ['scanner failed: '.$result->firstErrorLine()] : $findings);
    }

    /**
     * @return list<string>
     */
    private function findings(string $report, string $root): array
    {
        $leaks = JsonNode::parse($report);

        return array_map(
            static fn (JsonNode $leak): string => str_replace($root.'/', '', $leak->get('File')->text()).':'.$leak->get('StartLine')->text().' '.$leak->get('RuleID')->text().' commit '.substr($leak->get('Commit')->text(), 0, 8),
            $leaks instanceof JsonNode ? $leaks->items() : [],
        );
    }
}
