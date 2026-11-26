<?php

declare(strict_types=1);

namespace Intakt\Gates;

// A later change replaces an earlier one's row whatever their issue numbers, so the fragments need the order they reached the branch.
final readonly class HighlightHistory
{
    private const string COMMIT = '/^[0-9a-f]{40,64}(?: [0-9a-f]{40,64})*$/';

    private const int UNBORN_HEAD = 1;

    private const int ONLY_LANE = 0;

    private const int MERGED_SIDES = 0;

    private const int ADDED_BY_THE_MERGE = 1;

    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    public function orderOn(string $root): HighlightOrder|string
    {
        // First-parent history, because on the main branch a merge or squash commit adds a change's fragment when it is merged, not when it was written.
        $head = file_exists($root.'/.git') ? $this->runner->git(['rev-parse', '--verify', '--quiet', 'HEAD'], $root) : null;

        // With --quiet, exit status 1 is a branch without commits; any other failure is history git cannot read.
        if (!$head instanceof ProcessResult || self::UNBORN_HEAD === $head->exitCode) {
            return new HighlightOrder();
        }

        $unreadable = $this->unreadableHistoryFinding($root, $head);

        if (null !== $unreadable) {
            return $unreadable;
        }

        $places = $this->placesAddedIn($root, ['HEAD']);

        return is_string($places) ? $places : new HighlightOrder($places);
    }

    private function unreadableHistoryFinding(string $root, ProcessResult $head): ?string
    {
        if (!$head->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$head->firstErrorLine();
        }

        return $this->shallowHistoryFinding($root);
    }

    /**
     * @param list<string> $revisions
     *
     * @return array<string, list<int>>|string
     */
    private function placesAddedIn(string $root, array $revisions): array|string
    {
        $log = $this->runner->git(['log', '--first-parent', '--diff-merges=first-parent', '--no-renames', '--reverse', '--diff-filter=A', '--name-only', '--format=%H %P', ...$revisions, '--', HighlightFragments::DIRECTORY.'/'], $root);

        if (!$log->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$log->firstErrorLine();
        }

        $places = [];

        foreach ($this->additionsIn($log->output) as $position => [$parents, $names]) {
            $within = $this->placesWithin($root, $parents, $names);

            if (is_string($within)) {
                return $within;
            }

            foreach ($within as $name => $place) {
                $places[$name] = [$position, ...$place];
            }
        }

        return $places;
    }

    // A shallow clone hides the commits that added the older fragments, which would quietly reorder them.
    private function shallowHistoryFinding(string $root): ?string
    {
        $shallow = $this->runner->git(['rev-parse', '--is-shallow-repository'], $root);

        if (!$shallow->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$shallow->firstErrorLine();
        }

        return 'false' === trim($shallow->output) ? null : 'the history of '.HighlightFragments::DIRECTORY.'/ is shallow, so the order its fragments were merged in is unknown: fetch the full history (git fetch --unshallow)';
    }

    /**
     * @return list<array{list<string>, list<string>}>
     */
    private function additionsIn(string $log): array
    {
        $commits = [];

        foreach (explode("\n", $log) as $line) {
            if (1 === preg_match(self::COMMIT, rtrim($line))) {
                $commits[] = ['parents' => array_slice(explode(' ', rtrim($line)), 1), 'names' => []];
            } elseif ('' !== $line && [] !== $commits) {
                $commits[array_key_last($commits)]['names'][] = basename($line);
            }
        }

        return array_map(static fn (array $commit): array => [$commit['parents'], $commit['names']], $commits);
    }

    /**
     * @param list<string> $parents
     * @param list<string> $names
     *
     * @return array<string, list<int>>|string
     */
    private function placesWithin(string $root, array $parents, array $names): array|string
    {
        // The merged sides' own first-parent order decides, because a merge that adds several fragments at once brought in other lines of history (main fast-forwarded to a branch that merged main).
        if (count($names) < 2 || count($parents) < 2) {
            return array_fill_keys($names, []);
        }

        $sides = $this->placesOnSides($root, $parents, $names);

        if (is_string($sides)) {
            return $sides;
        }

        $addedByTheMerge = array_fill_keys(array_keys(array_diff_key(array_flip($names), $sides)), [self::ONLY_LANE, self::ADDED_BY_THE_MERGE]);

        return [...$sides, ...$addedByTheMerge];
    }

    /**
     * @param list<string> $parents
     * @param list<string> $names
     *
     * @return array<string, list<int>>|string
     */
    private function placesOnSides(string $root, array $parents, array $names): array|string
    {
        $places = [];

        foreach (array_slice($parents, 1) as $lane => $parent) {
            $side = $this->sidePlaces($root, [$parent, '^'.$parents[0]], $names);

            if (is_string($side)) {
                return $side;
            }

            // A fragment two parents both hold keeps the first parent's place, and its order against the other parent stays unknown.
            foreach ($side as $name => $place) {
                $places[$name] ??= [self::ONLY_LANE, self::MERGED_SIDES, $lane, ...$place];
            }
        }

        return $places;
    }

    /**
     * @param list<string> $range
     * @param list<string> $names
     *
     * @return array<string, list<int>>|string
     */
    private function sidePlaces(string $root, array $range, array $names): array|string
    {
        $present = $this->presentIn($root, $range[0], $names);

        if (is_string($present) || [] === $present) {
            return $present;
        }

        $side = $this->placesAddedIn($root, $range);

        return is_string($side) ? $side : array_intersect_key($side, array_flip($present));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>|string
     */
    private function presentIn(string $root, string $parent, array $names): array|string
    {
        // Only the parent's tree counts, because a fragment the side deleted again and the merge restored is the merge's own, not the side's.
        $tree = $this->runner->git(['ls-tree', '--name-only', $parent, '--', HighlightFragments::DIRECTORY.'/'], $root);

        if (!$tree->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$tree->firstErrorLine();
        }

        $paths = array_filter(explode("\n", $tree->output), static fn (string $path): bool => '' !== $path);

        return array_values(array_intersect($names, array_map(basename(...), $paths)));
    }
}
