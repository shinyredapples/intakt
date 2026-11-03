<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Closure;

final readonly class HighlightsUpToDateGate implements Gate
{
    private const string FILE = 'docs/highlights.md';

    private const string UPSTREAM = 'upstream';

    private const string FOUND_IN_THE_BETA = 'Found in the beta';

    public function __construct(
        private HighlightsRows $rows,
    ) {
    }

    public function name(): string
    {
        return 'highlights-up-to-date';
    }

    public function run(Workspace $workspace): GateResult
    {
        $file = $workspace->root.'/'.self::FILE;
        $contents = is_file($file) ? file_get_contents($file) : '';

        if (false === $contents) {
            return GateResult::failedBecause($this->name(), 'cannot read '.self::FILE);
        }

        $tracked = $workspace->hasGit() ? $workspace->git(['ls-files', '-z', '--cached']) : null;

        if ($tracked instanceof ProcessResult && !$tracked->succeeded()) {
            return GateResult::failedBecause($this->name(), 'cannot list tracked files: '.$tracked->firstErrorLine());
        }

        $rows = $this->rows->of($contents);
        $findings = [
            ...$this->reproductionsWithoutRow($this->reproductionsOn($workspace->root, $tracked), $rows),
            ...$this->plannedRowsAlreadyBuilt($this->existenceOn($workspace->root, $tracked), $rows),
        ];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @param list<string>        $reproductions
     * @param list<HighlightsRow> $rows
     *
     * @return list<string>
     */
    private function reproductionsWithoutRow(array $reproductions, array $rows): array
    {
        $beta = array_filter($rows, static fn (HighlightsRow $row): bool => self::FOUND_IN_THE_BETA === $row->section);
        $findings = [];

        foreach ($reproductions as $reproduction) {
            if ([] === array_filter($beta, static fn (HighlightsRow $row): bool => $row->names($reproduction))) {
                $findings[] = self::FILE.': `'.$reproduction.'/` has no row in "'.self::FOUND_IN_THE_BETA.'" whose Where names it, add one';
            }
        }

        return $findings;
    }

    /**
     * @param Closure(string): bool $exists
     * @param list<HighlightsRow>   $rows
     *
     * @return list<string>
     */
    private function plannedRowsAlreadyBuilt(Closure $exists, array $rows): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $missing = array_filter($row->paths, static fn (string $path): bool => !$exists($path));

            if (HighlightStatus::Planned->value === $row->status && [] !== $row->paths && [] === $missing) {
                $named = implode(', ', array_map(static fn (string $path): string => '`'.$path.'`', $row->paths));
                $findings[] = self::FILE.':'.$row->line.': status "planned", but every path in Where exists ('.$named.'), update its status to in progress or done';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function reproductionsOn(string $root, ?ProcessResult $tracked): array
    {
        // Without git there is no branch, so the working tree stands in for it.
        if (!$tracked instanceof ProcessResult) {
            $folders = glob($root.'/'.self::UPSTREAM.'/*', GLOB_ONLYDIR | GLOB_NOSORT);

            return array_map(static fn (string $folder): string => self::UPSTREAM.'/'.basename($folder), false === $folders ? [] : $folders);
        }

        $reproductions = [];

        foreach (explode("\0", $tracked->output) as $file) {
            $parts = explode('/', $file, 3);

            // Only a file inside a folder makes the folder a reproduction, so upstream/README.md is none.
            if (3 === count($parts) && self::UPSTREAM === $parts[0]) {
                $reproductions[self::UPSTREAM.'/'.$parts[1]] = true;
            }
        }

        return array_keys($reproductions);
    }

    /**
     * @return Closure(string): bool
     */
    private function existenceOn(string $root, ?ProcessResult $tracked): Closure
    {
        if (!$tracked instanceof ProcessResult) {
            return static fn (string $path): bool => file_exists($root.'/'.$path);
        }

        $known = [];

        foreach (explode("\0", $tracked->output) as $file) {
            for ($path = $file; '' !== $path && '.' !== $path; $path = dirname($path)) {
                $known[$path] = true;
            }
        }

        return static fn (string $path): bool => isset($known[$path]);
    }
}
