<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LeftUndoneGate implements Gate
{
    // Because fixtures and their copies hold such comments on purpose, as the input a test feeds a rule.
    private const array FIXTURE_PREFIXES = ['tests/Fixtures/', 'fixtures/'];
    private const string HOW_TO_COMPLY = ' comment left in the change; finish the work, or open an issue and write TODO(#<issue>): <reason>';
    // Because git prints no lines for a file it takes for binary, such as PHP holding a NUL byte in a string.
    private const array DIFF_OPTIONS = ['--text', '--no-color', '--no-ext-diff', '--no-textconv', '--find-renames', '--diff-filter=d'];

    public function __construct(
        private AddedLines $addedLines = new AddedLines(),
        private LeftUndoneComments $comments = new LeftUndoneComments(),
        private LeftUndoneCode $code = new LeftUndoneCode(),
        private CommentLanguages $languages = new CommentLanguages(),
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

        foreach ($this->scannedLinesSince($workspace, $mergeBase) as $file) {
            array_push($findings, ...$this->findingsIn($workspace, $file));
        }

        return $findings;
    }

    /**
     * @return list<array{string, ?list<int>, CommentLanguage}>
     */
    private function scannedLinesSince(Workspace $workspace, string $mergeBase): array
    {
        $paths = $this->nulSeparated($this->gitOutput($workspace, ['diff', '--name-only', '-z', ...self::DIFF_OPTIONS, $mergeBase]));
        $patch = $this->gitOutput($workspace, ['diff', '--unified=0', ...self::DIFF_OPTIONS, $mergeBase]);
        // Because a file git does not track yet has no lines on the base, every line of it is added.
        $untracked = array_map(static fn (string $path): array => [$path, null], $workspace->untrackedPaths());
        $scanned = [];

        foreach ([...$this->addedLines->byPath($paths, $patch), ...$untracked] as [$path, $addedLines]) {
            $language = $this->isFixture($path) ? null : $this->languages->languageOf($workspace, $path);

            if ($language instanceof CommentLanguage) {
                $scanned[] = [$path, $addedLines, $language];
            }
        }

        return $scanned;
    }

    /**
     * @param array{string, ?list<int>, CommentLanguage} $file
     *
     * @return list<string>
     */
    private function findingsIn(Workspace $workspace, array $file): array
    {
        [$path, $addedLines, $language] = $file;
        $source = $this->sourceOf($workspace, $path);

        if (null === $source) {
            return [$path.' cannot be read'];
        }

        $lines = $addedLines ?? $this->everyLineOf($source);
        $findings = [];

        foreach ($this->comments->markersOn($source, $lines, $language) as $line => $marker) {
            $findings[] = $path.':'.$line.': '.$marker.self::HOW_TO_COMPLY;
        }

        $code = $language->holdsPhpCode() ? $this->code->findingsOn($path, $source, $lines) : [];

        foreach ($code as $line => $finding) {
            $findings[] = $path.':'.$line.': '.$finding;
        }

        return $findings;
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

    private function isFixture(string $path): bool
    {
        return array_any(self::FIXTURE_PREFIXES, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
