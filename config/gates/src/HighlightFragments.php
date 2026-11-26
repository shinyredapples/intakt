<?php

declare(strict_types=1);

namespace Intakt\Gates;

// One fragment per change and no committed page, so two pull requests never touch the same file.
final readonly class HighlightFragments
{
    public const string ASSEMBLED = 'docs/highlights.md';

    private const string DIRECTORY = 'docs/highlights.d';

    private const string FRAGMENT_NAME = '/^\d+-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/';

    private const string COMMIT = '/^[0-9a-f]{40,64}(?: [0-9a-f]{40,64})*$/';

    private const int UNBORN_HEAD = 1;

    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    public function areOn(string $root): bool
    {
        return is_dir($root.'/'.self::DIRECTORY);
    }

    // The gates read the page as bin/highlights would write it, so their rules never judge a stale or hand-edited local copy.
    public function pageOn(string $root): ?HighlightAssembly
    {
        if ($this->areOn($root)) {
            return $this->assembled($root);
        }

        $file = $root.'/'.self::ASSEMBLED;

        if (!is_file($file)) {
            return null;
        }

        $contents = $this->contentsOf($file);

        return null === $contents ? new HighlightAssembly('', ['cannot read '.self::ASSEMBLED]) : new HighlightAssembly($contents, []);
    }

    /**
     * @return list<string>
     */
    public function committedPageFindings(string $root, ?ProcessResult $tracked): array
    {
        // Only a tracked page reaches other changes, because a local copy is ignored; without fragments the page is hand-written by design.
        if (!$tracked instanceof ProcessResult || !$this->areOn($root) || !in_array(self::ASSEMBLED, explode("\0", $tracked->output), true)) {
            return [];
        }

        return [self::ASSEMBLED.' is committed, but bin/highlights generates it from '.self::DIRECTORY.'/ and git ignores it: remove it from the index (git rm --cached '.self::ASSEMBLED.') and put the change in a fragment'];
    }

    /**
     * @return list<string>
     */
    public function localPageFindings(string $root, HighlightAssembly $page): array
    {
        $file = $root.'/'.self::ASSEMBLED;

        // A broken assembly already fails with its own findings, so only a page next to a sound one is compared.
        if (!$this->areOn($root) || [] !== $page->findings || !is_file($file) || $this->contentsOf($file) === $page->text) {
            return [];
        }

        return [self::ASSEMBLED.' is not the assembly of '.self::DIRECTORY.'/: put a change in a fragment, never in the page, and run bin/highlights'];
    }

    public function assembled(string $root): HighlightAssembly
    {
        $layout = $this->linesOf($root.'/'.HighlightLayout::FILE);

        if (null === $layout) {
            return new HighlightAssembly('', ['cannot read '.HighlightLayout::FILE]);
        }

        $history = $this->additionsOn($root);

        if (is_string($history)) {
            return new HighlightAssembly('', [$history]);
        }

        $layout = HighlightLayout::fromLines($layout);
        $names = $this->fragmentNames($root);
        $findings = $this->nameFindings($names);
        $rowsByFragment = [];

        foreach ($this->fragmentsInOrder($names, $history) as $name) {
            [$rowsByFragment[$name], $fragmentFindings] = $this->rowsOf($root, $name, $layout);
            $findings = [...$findings, ...$fragmentFindings];
        }

        $findings = [...$findings, ...$this->unorderedRowFindings($rowsByFragment, $history)];
        $rows = array_reduce($rowsByFragment, $this->withReplacedRows(...), []);

        return new HighlightAssembly([] === $findings ? $layout->assembledWith($rows) : '', $findings);
    }

    /**
     * @return list<string>|null
     */
    private function linesOf(string $file): ?array
    {
        $contents = $this->contentsOf($file);

        return null === $contents ? null : array_map(rtrim(...), explode("\n", $contents));
    }

    /**
     * @return array<string, int>|string
     */
    private function additionsOn(string $root): array|string
    {
        // First-parent history, because on the main branch a merge or squash commit adds a change's fragment when it is merged, not when it was written.
        $head = file_exists($root.'/.git') ? $this->runner->git(['rev-parse', '--verify', '--quiet', 'HEAD'], $root) : null;

        // With --quiet, exit status 1 is a branch without commits; any other failure is history git cannot read.
        if (!$head instanceof ProcessResult || self::UNBORN_HEAD === $head->exitCode) {
            return [];
        }

        $unreadable = $this->unreadableHistoryFinding($root, $head);

        if (null !== $unreadable) {
            return $unreadable;
        }

        $groups = $this->groupsAddedIn($root, ['HEAD']);

        return is_string($groups) ? $groups : $this->ranksOf($groups);
    }

    /**
     * @return list<string>
     */
    private function fragmentNames(string $root): array
    {
        $entries = scandir($root.'/'.self::DIRECTORY);

        if (false === $entries) {
            return [];
        }

        return array_values(array_diff($entries, ['.', '..', basename(HighlightLayout::FILE)]));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function nameFindings(array $names): array
    {
        $misnamed = array_filter($names, static fn (string $name): bool => 1 !== preg_match(self::FRAGMENT_NAME, $name));

        return array_values(array_map(
            static fn (string $name): string => self::DIRECTORY.'/'.$name.': a fragment is named <issue>-<slug>.md, the slug in lowercase letters, digits and hyphens',
            $misnamed,
        ));
    }

    /**
     * @param list<string>       $names
     * @param array<string, int> $history
     *
     * @return list<string>
     */
    private function fragmentsInOrder(array $names, array $history): array
    {
        $fragments = array_values(array_filter($names, static fn (string $name): bool => 1 === preg_match(self::FRAGMENT_NAME, $name)));

        // A later change replaces an earlier one's row whatever their issue numbers, so fragments apply in the order they reached the branch; an uncommitted fragment is the newest.
        usort($fragments, static function (string $a, string $b) use ($history): int {
            $byHistory = ($history[$a] ?? PHP_INT_MAX) <=> ($history[$b] ?? PHP_INT_MAX);

            return 0 === $byHistory ? strnatcmp($a, $b) : $byHistory;
        });

        return $fragments;
    }

    private function unreadableHistoryFinding(string $root, ProcessResult $head): ?string
    {
        if (!$head->succeeded()) {
            return 'cannot read the history of '.self::DIRECTORY.'/: '.$head->firstErrorLine();
        }

        return $this->shallowHistoryFinding($root);
    }

    /**
     * @param list<string> $revisions
     *
     * @return list<list<string>>|string
     */
    private function groupsAddedIn(string $root, array $revisions): array|string
    {
        $log = $this->runner->git(['log', '--first-parent', '--diff-merges=first-parent', '--no-renames', '--reverse', '--diff-filter=A', '--name-only', '--format=%H %P', ...$revisions, '--', self::DIRECTORY.'/'], $root);

        if (!$log->succeeded()) {
            return 'cannot read the history of '.self::DIRECTORY.'/: '.$log->firstErrorLine();
        }

        $groups = [];

        foreach ($this->additionsIn($log->output) as [$parents, $names]) {
            $ordered = $this->mergedSideOrder($root, $parents, $names);

            if (is_string($ordered)) {
                return $ordered;
            }

            $groups = [...$groups, ...$ordered];
        }

        return $groups;
    }

    /**
     * @param list<list<string>> $groups
     *
     * @return array<string, int>
     */
    private function ranksOf(array $groups): array
    {
        $ranks = [];

        foreach ($groups as $rank => $names) {
            foreach ($names as $name) {
                $ranks[$name] = $rank;
            }
        }

        return $ranks;
    }

    /**
     * @return list<array{list<string>, list<string>}>
     */
    private function additionsIn(string $log): array
    {
        $commits = [];

        foreach (explode("\n", $log) as $line) {
            if (1 === preg_match(self::COMMIT, rtrim($line))) {
                $commits[] = [array_slice(explode(' ', rtrim($line)), 1), []];
            } elseif ('' !== $line && [] !== $commits) {
                $commits[array_key_last($commits)][1][] = basename($line);
            }
        }

        return $commits;
    }

    /**
     * @param list<string> $parents
     * @param list<string> $names
     *
     * @return list<list<string>>|string
     */
    private function mergedSideOrder(string $root, array $parents, array $names): array|string
    {
        // A merge that adds several fragments at once brought in another line of history (main fast-forwarded to a branch that merged main), whose own first-parent order says which reached it first.
        if (count($names) < 2 || count($parents) < 2) {
            return [$names];
        }

        $side = $this->groupsAddedIn($root, [...array_slice($parents, 1), '^'.$parents[0]]);

        if (is_string($side)) {
            return $side;
        }

        $groups = array_values(array_filter(
            array_map(static fn (array $group): array => array_values(array_intersect($group, $names)), $side),
            static fn (array $group): bool => [] !== $group,
        ));
        $addedByTheMerge = array_values(array_diff($names, ...$groups));

        return [] === $addedByTheMerge ? $groups : [...$groups, $addedByTheMerge];
    }

    // A shallow clone hides the commits that added the older fragments, which would quietly reorder them.
    private function shallowHistoryFinding(string $root): ?string
    {
        $shallow = $this->runner->git(['rev-parse', '--is-shallow-repository'], $root);

        if (!$shallow->succeeded()) {
            return 'cannot read the history of '.self::DIRECTORY.'/: '.$shallow->firstErrorLine();
        }

        return 'false' === trim($shallow->output) ? null : 'the history of '.self::DIRECTORY.'/ is shallow, so the order its fragments were merged in is unknown: fetch the full history (git fetch --unshallow)';
    }

    /**
     * @return array{array<string, array<string, string>>, list<string>}
     */
    private function rowsOf(string $root, string $name, HighlightLayout $layout): array
    {
        $file = self::DIRECTORY.'/'.$name;
        $lines = $this->linesOf($root.'/'.$file);

        return null === $lines ? [[], ['cannot read '.$file]] : new HighlightFragment($file, $layout)->rowsOf($lines);
    }

    /**
     * @param array<string, array<string, array<string, string>>> $rowsByFragment
     * @param array<string, int>                                  $history
     *
     * @return list<string>
     */
    private function unorderedRowFindings(array $rowsByFragment, array $history): array
    {
        // Fragments without a distinct place in history would otherwise fall back to their numbers, and an older change could silently replace a newer row.
        $setBy = [];
        $findings = [];

        foreach ($rowsByFragment as $name => $rows) {
            $rank = $history[$name] ?? PHP_INT_MAX;

            foreach ($this->rowKeysOf($rows) as [$section, $key]) {
                $earlier = $setBy[$rank][$section][$key] ?? null;
                $setBy[$rank][$section][$key] = $earlier ?? $name;

                if (null !== $earlier) {
                    $findings[] = self::DIRECTORY.'/'.$earlier.' and '.self::DIRECTORY.'/'.$name.' both set row "'.$key.'" of section "'.$section.'", but git history does not say which came first: add them in separate commits, or assemble in a clone with the full history';
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array<string, string>> $rows
     *
     * @return list<array{string, string}>
     */
    private function rowKeysOf(array $rows): array
    {
        $keys = [];

        foreach ($rows as $section => $sectionRows) {
            foreach (array_keys($sectionRows) as $key) {
                $keys[] = [$section, $key];
            }
        }

        return $keys;
    }

    /**
     * @param array<string, array<string, string>> $rows
     * @param array<string, array<string, string>> $replacements
     *
     * @return array<string, array<string, string>>
     */
    private function withReplacedRows(array $rows, array $replacements): array
    {
        foreach ($replacements as $section => $sectionRows) {
            foreach ($sectionRows as $key => $row) {
                $rows[$section][$key] = $row;
            }
        }

        return $rows;
    }

    private function contentsOf(string $file): ?string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return false === $contents ? null : $contents;
    }
}
