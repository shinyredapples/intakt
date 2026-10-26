<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class HistorySecretsGate extends GitleaksGate
{
    private const string CONFIGURATION = 'gitleaks.toml';
    private const array FULL_DIFF_OPTIONS = ['--diff-merges=first-parent', '--text', '--no-textconv', '--no-ext-diff'];

    public function name(): string
    {
        return 'secrets-history';
    }

    public function run(Workspace $workspace): GateResult
    {
        $mergeBase = $workspace->mergeBase();

        if (!$workspace->hasGit()) {
            return GateResult::passedBecause($this->name(), 'skipped, no git history in this tree');
        }

        if (null === $mergeBase) {
            return GateResult::failedBecause($this->name(), 'no base branch found, pass --base=<ref>');
        }

        if (!$this->hasCommits($workspace, $mergeBase)) {
            return GateResult::passed($this->name());
        }

        return $this->scanWithConfigurationFrom($workspace, $mergeBase);
    }

    private function scanWithConfigurationFrom(Workspace $workspace, string $mergeBase): GateResult
    {
        $configPath = $this->baseConfiguration($workspace, $mergeBase);

        if (null === $configPath) {
            return GateResult::failedBecause($this->name(), 'the base commit has no config/gates/'.self::CONFIGURATION.', the scan cannot run');
        }

        $result = $this->scan($workspace, new ScanTarget($workspace->root, ['--log-opts='.implode(' ', [...self::FULL_DIFF_OPTIONS, $mergeBase.'..HEAD'])]), $configPath);

        unlink($configPath);

        return $result;
    }

    private function configurationSource(Workspace $workspace, string $mergeBase): string
    {
        $baseHasGates = $workspace->command(['git', 'cat-file', '-e', $mergeBase.':config/gates'])->succeeded();

        return ($baseHasGates ? $mergeBase : 'HEAD').':config/gates/'.self::CONFIGURATION;
    }

    private function baseConfiguration(Workspace $workspace, string $mergeBase): ?string
    {
        $base = $workspace->command(['git', 'show', $this->configurationSource($workspace, $mergeBase)]);
        $created = tempnam(sys_get_temp_dir(), 'gitleaks-config');

        if (!$base->succeeded() || false === $created) {
            return null;
        }

        $path = $created.'.toml';
        if (!rename($created, $path)) {
            unlink($created);

            return null;
        }

        if (strlen($base->output) !== file_put_contents($path, $base->output)) {
            unlink($path);

            return null;
        }

        return $path;
    }

    private function hasCommits(Workspace $workspace, string $mergeBase): bool
    {
        $result = $workspace->command(['git', 'rev-list', '--count', $mergeBase.'..HEAD']);

        return !$result->succeeded() || '0' !== trim($result->output);
    }

    protected function bypassesIn(Workspace $workspace): array
    {
        $range = ($workspace->mergeBase() ?? 'HEAD').'..HEAD';

        return [
            ...$this->ignoreFileFindings($workspace),
            ...$this->commitsWith($workspace, [...self::FULL_DIFF_OPTIONS, '-G', self::INLINE_ALLOW, $range], 'carries an inline allow directive that hides findings from the scan'),
            ...$this->commitsWith($workspace, [...self::FULL_DIFF_OPTIONS, $range, '--', ...self::UNSCANNED_FOLDERS], 'adds files in a folder the scanner skips'),
        ];
    }

    /**
     * @param list<string> $selection
     *
     * @return list<string>
     */
    private function commitsWith(Workspace $workspace, array $selection, string $problem): array
    {
        $commits = $workspace->command(['git', 'log', '--format=%h', ...$selection]);

        if (!$commits->succeeded()) {
            return ['cannot search the commits: '.$commits->firstErrorLine()];
        }

        return array_map(static fn (string $commit): string => 'commit '.$commit.' '.$problem, $this->lines($commits->output));
    }
}
