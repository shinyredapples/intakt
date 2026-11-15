<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LeftUndoneGate implements Gate
{
    // Because fixtures and their copies hold such comments on purpose, as the input a test feeds a rule.
    private const array FIXTURE_PREFIXES = ['tests/Fixtures/', 'fixtures/'];
    private const string HOW_TO_COMPLY = ' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>';
    private const array DIFF_OPTIONS = ['--no-color', '--no-ext-diff', '--no-textconv', '--find-renames', '--diff-filter=d'];

    public function __construct(
        private AddedLines $addedLines = new AddedLines(),
        private LeftUndoneComments $comments = new LeftUndoneComments(),
    ) {
    }

    public function name(): string
    {
        return 'left-undone';
    }

    public function run(Workspace $workspace): GateResult
    {
        if (!$workspace->hasGit()) {
            return GateResult::passedBecause($this->name(), 'skipped, no git history in this tree');
        }

        $mergeBase = $workspace->mergeBase();

        if (null === $mergeBase) {
            return GateResult::failedBecause($this->name(), 'no base branch found, pass --base=<ref>');
        }

        try {
            $findings = $this->findingsSince($workspace, $mergeBase);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function findingsSince(Workspace $workspace, string $mergeBase): array
    {
        $findings = [];

        foreach ($this->scannedLinesSince($workspace, $mergeBase) as [$path, $addedLines]) {
            $source = $this->sourceOf($workspace, $path);

            if (null === $source) {
                $findings[] = $path.' cannot be read';

                continue;
            }

            foreach ($this->comments->markersOn($source, $addedLines ?? $this->everyLineOf($source)) as $line => $marker) {
                $findings[] = $path.':'.$line.': '.$marker.self::HOW_TO_COMPLY;
            }
        }

        return $findings;
    }

    /**
     * @return list<array{string, ?list<int>}>
     */
    private function scannedLinesSince(Workspace $workspace, string $mergeBase): array
    {
        $paths = $this->nulSeparated($this->gitOutput($workspace, ['diff', '--name-only', '-z', ...self::DIFF_OPTIONS, $mergeBase]));
        $patch = $this->gitOutput($workspace, ['diff', '--unified=0', ...self::DIFF_OPTIONS, $mergeBase]);
        // Because a file git does not track yet has no lines on the base, every line of it is added.
        $untracked = array_map(static fn (string $path): array => [$path, null], $workspace->untrackedPaths());

        return array_values(array_filter(
            [...$this->addedLines->byPath($paths, $patch), ...$untracked],
            fn (array $file): bool => $this->isScanned($workspace, $file[0]),
        ));
    }

    private function isScanned(Workspace $workspace, string $path): bool
    {
        return $workspace->isPhpFileInScope($path) && !array_any(self::FIXTURE_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }

    private function sourceOf(Workspace $workspace, string $path): ?string
    {
        $file = $workspace->root.'/'.$path;

        if (is_link($file) || !is_file($file)) {
            return '';
        }

        $source = is_readable($file) ? file_get_contents($file) : false;

        return false === $source ? null : $source;
    }

    /**
     * @return list<int>
     */
    private function everyLineOf(string $source): array
    {
        return range(1, substr_count($source, "\n") + 1);
    }

    /**
     * @param list<string> $arguments
     */
    private function gitOutput(Workspace $workspace, array $arguments): string
    {
        $result = $workspace->git($arguments);

        if (!$result->succeeded()) {
            throw new ScopeFailure('git '.$arguments[0].' failed: '.$result->firstErrorLine());
        }

        return $result->output;
    }

    /**
     * @return list<string>
     */
    private function nulSeparated(string $output): array
    {
        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => '' !== $path));
    }
}
