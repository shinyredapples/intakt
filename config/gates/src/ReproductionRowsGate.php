<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ReproductionRowsGate implements Gate
{
    private const string HIGHLIGHTS = 'docs/highlights.md';

    private const string SECTION = '## Found in the beta';

    private const string NEXT_SECTION = '/^#{1,2} /';

    private const string REPRODUCTION_FILE = '#^upstream/([^/]+)/#';

    // A folder name ends at the first character a path or a code span cannot hold, so `upstream/a-bug-two/` names no `a-bug`.
    private const string NAMED_FOLDER = '#\bupstream/([^/\s`|<>]+)#';

    public function __construct(
        private FencedCodeBlocks $codeBlocks,
    ) {
    }

    public function name(): string
    {
        return 'reproductions';
    }

    public function run(Workspace $workspace): GateResult
    {
        try {
            $folders = $this->reproductionFolders($workspace);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $contents = is_file($workspace->root.'/'.self::HIGHLIGHTS) ? file_get_contents($workspace->root.'/'.self::HIGHLIGHTS) : '';

        if (false === $contents) {
            return GateResult::failedBecause($this->name(), 'cannot read '.self::HIGHLIGHTS);
        }

        $findings = array_map(
            static fn (string $folder): string => 'upstream/'.$folder.'/ has no row in "Found in the beta" of '.self::HIGHLIGHTS,
            array_values(array_diff($folders, $this->foldersWithARow($contents))),
        );

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return list<string>
     */
    private function reproductionFolders(Workspace $workspace): array
    {
        $folders = [];

        foreach ($workspace->repositoryFiles() as $path) {
            if (1 === preg_match(self::REPRODUCTION_FILE, $path, $match)) {
                $folders[$match[1]] = true;
            }
        }

        $names = array_keys($folders);
        sort($names);

        return array_map(strval(...), $names);
    }

    /**
     * @return list<string>
     */
    private function foldersWithARow(string $contents): array
    {
        $named = [];

        foreach ($this->sectionRows($contents) as $row) {
            preg_match_all(self::NAMED_FOLDER, $row, $matches);
            $named = [...$named, ...$matches[1]];
        }

        return $named;
    }

    /**
     * @return list<string>
     */
    private function sectionRows(string $contents): array
    {
        $rows = [];
        $inSection = false;

        foreach ($this->codeBlocks->blanked(explode("\n", $contents)) as $line) {
            if (1 === preg_match(self::NEXT_SECTION, $line)) {
                $inSection = self::SECTION === rtrim($line);

                continue;
            }

            // Only a table row counts, because the section's prose names `upstream/<slug>/` as a pattern.
            if ($inSection && str_starts_with(ltrim($line), '|')) {
                $rows[] = $line;
            }
        }

        return $rows;
    }
}
