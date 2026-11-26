<?php

declare(strict_types=1);

namespace Intakt\Gates;

// A later change replaces an earlier one's row whatever their issue numbers, so the fragments need the order they reached the branch.
final readonly class HighlightHistory
{
    private const string COMMIT = '/^[0-9a-f]{40,64}(?: [0-9a-f]{40,64})*$/';

    private const int UNBORN_HEAD = 1;

    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
    ) {
    }

    /**
     * @return array<string, int>|string
     */
    public function ranksOn(string $root): array|string
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
     * @return list<list<string>>|string
     */
    private function groupsAddedIn(string $root, array $revisions): array|string
    {
        $log = $this->runner->git(['log', '--first-parent', '--diff-merges=first-parent', '--no-renames', '--reverse', '--diff-filter=A', '--name-only', '--format=%H %P', ...$revisions, '--', HighlightFragments::DIRECTORY.'/'], $root);

        if (!$log->succeeded()) {
            return 'cannot read the history of '.HighlightFragments::DIRECTORY.'/: '.$log->firstErrorLine();
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
     * @return list<list<string>>|string
     */
    private function mergedSideOrder(string $root, array $parents, array $names): array|string
    {
        // The merged side's own first-parent order decides, because a merge that adds several fragments at once brought in another line of history (main fast-forwarded to a branch that merged main).
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
}
