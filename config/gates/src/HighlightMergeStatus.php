<?php

declare(strict_types=1);

namespace Intakt\Gates;

// A build writes "in progress" while its pull request is open and cannot know when it merges, so a fragment that is on the
// main branch came with a merged pull request and its "in progress" rows read as done. Without a main branch nothing changes.
final readonly class HighlightMergeStatus
{
    // The main branch as a clone or CI checkout knows it, then as a local branch.
    private const array MAIN_REFS = ['refs/remotes/origin/main', 'refs/heads/main'];

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    public function __construct(
        private ProcessRunner $runner,
    ) {
    }

    /**
     * @return array<string, true> fragment file names added on the main branch
     */
    public function fragmentsOnMain(string $root, string $directory): array
    {
        if (!file_exists($root.'/.git')) {
            return [];
        }

        foreach (self::MAIN_REFS as $ref) {
            if (!$this->runner->git(['rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $root)->succeeded()) {
                continue;
            }

            $log = $this->runner->git(['log', $ref, '--no-renames', '--diff-filter=A', '--name-only', '--format=', '--', $directory.'/'], $root);

            if (!$log->succeeded()) {
                return [];
            }

            $names = array_map(basename(...), array_filter(explode("\n", $log->output), static fn (string $line): bool => '' !== $line));

            return array_fill_keys($names, true);
        }

        return [];
    }

    /**
     * @param array<string, array<string, string>> $rows
     *
     * @return array<string, array<string, string>>
     */
    public function settled(array $rows, HighlightLayout $layout): array
    {
        foreach ($rows as $section => $sectionRows) {
            $kind = $layout->kindOf($section);

            foreach ($sectionRows as $key => $line) {
                $rows[$section][$key] = HighlightRowKind::ListItem === $kind ? $this->settledItem($line) : $this->settledTableRow($line);
            }
        }

        return $rows;
    }

    // A list item names its status last, in parentheses.
    private function settledItem(string $line): string
    {
        $inProgress = preg_quote(HighlightStatus::InProgress->value, '/');
        $settled = preg_replace('/\((?:'.$inProgress.')\b([^()]*(?:\([^()]*\)[^()]*)*\)\.?\s*)$/', '('.HighlightStatus::Done->value.'$1', $line);

        return $settled ?? $line;
    }

    // The feature cell comes first and keeps its text; the later cell that reads exactly "in progress" is the status.
    private function settledTableRow(string $line): string
    {
        $cells = preg_split(self::CELL_BORDER, $line);

        if (false === $cells) {
            return $line;
        }

        foreach ($cells as $index => $cell) {
            if ($index > 1 && HighlightStatus::InProgress->value === trim($cell)) {
                $cells[$index] = str_replace(HighlightStatus::InProgress->value, HighlightStatus::Done->value, $cell);

                return implode('|', $cells);
            }
        }

        return $line;
    }
}
