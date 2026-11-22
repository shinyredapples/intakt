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

    protected function bypassesIn(Workspace $workspace): array
    {
        $range = ($workspace->mergeBase() ?? 'HEAD').'..HEAD';

        return [
            ...$this->ignoreFileFindings($workspace),
            ...$this->commitsWith($workspace->git(['log', '--format=%h', ...self::FULL_DIFF_OPTIONS, '-G', self::INLINE_ALLOW, $range]), 'carries an inline allow directive that hides findings from the scan'),
            ...$this->commitsWith($workspace->gitWithPathspecMagic(['log', '--format=%h', ...self::FULL_DIFF_OPTIONS, $range, '--', ...self::UNSCANNED_FOLDERS]), 'adds files in a folder the scanner skips'),
        ];
    }

    private function hasCommits(Workspace $workspace, string $mergeBase): bool
    {
        $result = $workspace->git(['rev-list', '--count', $mergeBase.'..HEAD']);

        return !$result->succeeded() || '0' !== trim($result->output);
    }

    private function scanWithConfigurationFrom(Workspace $workspace, string $mergeBase): GateResult
    {
        $source = $this->configurationSource($workspace);

        if (null === $source) {
            return GateResult::failedBecause($this->name(), 'cannot read config/gates at the tip of the base, the scan cannot run');
        }

        $configPath = $this->baseConfiguration($workspace, $source);

        if (null === $configPath) {
            return GateResult::failedBecause($this->name(), 'the base has no config/gates/'.self::CONFIGURATION.', the scan cannot run');
        }

        $result = $this->scan($workspace, new ScanTarget($workspace->root, ['--log-opts='.implode(' ', [...self::FULL_DIFF_OPTIONS, $mergeBase.'..HEAD'])]), $configPath);

        unlink($configPath);

        return $result;
    }

    private function configurationSource(Workspace $workspace): ?string
    {
        $tip = $workspace->baseTip();

        if (null === $tip) {
            return null;
        }

        $listing = $workspace->git(['ls-tree', '-d', '--name-only', $tip, '--', 'config/gates']);

        if (!$listing->succeeded()) {
            return null;
        }

        return '' === trim($listing->output) ? 'HEAD' : $tip;
    }

    private function baseConfiguration(Workspace $workspace, string $source): ?string
    {
        $base = $workspace->git(['show', $source.':config/gates/'.self::CONFIGURATION]);
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

    /**
     * @return list<string>
     */
    private function commitsWith(ProcessResult $commits, string $problem): array
    {
        if (!$commits->succeeded()) {
            return ['cannot search the commits: '.$commits->firstErrorLine()];
        }

        return array_map(static fn (string $commit): string => 'commit '.$commit.' '.$problem, $this->lines($commits->output));
    }
}
