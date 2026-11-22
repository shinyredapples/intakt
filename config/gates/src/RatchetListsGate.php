<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class RatchetListsGate implements Gate
{
    private const string HEAD = 'head:';
    private const string BASE = 'base:';

    public function __construct(
        private PhpStanIncludes $includes = new PhpStanIncludes(),
        private IgnoredErrorsReader $reader = new IgnoredErrorsReader(),
    ) {
    }

    public function name(): string
    {
        return 'ratchet';
    }

    public function run(Workspace $workspace): GateResult
    {
        $lists = $this->includes->ratchetListsIn($this->workingTreeFile($workspace, PhpStanIncludes::CONFIGURATION) ?? '');

        if (null === $lists) {
            return GateResult::failedBecause($this->name(), 'cannot read the includes of '.PhpStanIncludes::CONFIGURATION.': each must be a relative path inside the repository on its own "- path" line');
        }

        if ([] === $lists) {
            return GateResult::passedBecause($this->name(), 'no ratchet lists');
        }

        if (!$workspace->hasGit()) {
            return GateResult::passedBecause($this->name(), 'skipped, no git history in this tree');
        }

        $mergeBase = $workspace->mergeBase();

        if (null === $mergeBase) {
            return GateResult::failedBecause($this->name(), 'no base branch found, pass --base=<ref>');
        }

        $findings = $this->findingsAgainst($workspace, $mergeBase, $lists);

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    private function workingTreeFile(Workspace $workspace, string $path): ?string
    {
        $file = $workspace->root.'/'.$path;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function findingsAgainst(Workspace $workspace, string $mergeBase, array $paths): array
    {
        $baseLists = $this->includes->ratchetListsIn($this->fileAt($workspace, $mergeBase, PhpStanIncludes::CONFIGURATION) ?? '');

        if (null === $baseLists) {
            return ['cannot read the includes of '.PhpStanIncludes::CONFIGURATION.' on the target branch'];
        }

        [$lists, $findings] = $this->versionsOf($workspace, $mergeBase, $paths);
        array_push($findings, ...$this->listsAheadOfTheirInclude($lists, $paths, $baseLists));

        if (!$workspace->hasTool('phpstan')) {
            return [...$findings, 'missing tool phpstan'];
        }

        return [...$findings, ...$this->shrinkFindings($this->ignoredErrors($workspace, $lists), $paths)];
    }

    private function fileAt(Workspace $workspace, string $commit, string $path): ?string
    {
        $shown = $workspace->git(['show', $commit.':'.$path]);

        return $shown->succeeded() ? $shown->output : null;
    }

    /**
     * @param list<string> $paths
     *
     * @return array{array<string, RatchetListFile>, list<string>}
     */
    private function versionsOf(Workspace $workspace, string $mergeBase, array $paths): array
    {
        $lists = [];
        $findings = [];

        foreach ($paths as $path) {
            $head = $this->workingTreeFile($workspace, $path);
            $base = $this->fileAt($workspace, $mergeBase, $path);

            if (null === $head) {
                $findings[] = $path.': included but missing';
                continue;
            }

            $lists[self::HEAD.$path] = new RatchetListFile($path, $head);
            array_push($findings, ...$lists[self::HEAD.$path]->problems());

            if (null !== $base) {
                $lists[self::BASE.$path] = new RatchetListFile($path, $base);
            }
        }

        return [$lists, $findings];
    }

    /**
     * @param array<string, RatchetListFile> $lists
     * @param list<string>                   $paths
     * @param list<string>                   $baseLists
     *
     * @return list<string>
     */
    private function listsAheadOfTheirInclude(array $lists, array $paths, array $baseLists): array
    {
        $new = array_filter($paths, static fn (string $path): bool => isset($lists[self::HEAD.$path]) && !isset($lists[self::BASE.$path]));

        return array_values(array_map(
            static fn (string $path): string => $path.': is not on the target branch, but its include is; a list arrives only with its include, in the change that switches its rule on',
            array_intersect($new, $baseLists),
        ));
    }

    /**
     * @param array<string, RatchetListFile> $lists
     *
     * @return array<string, IgnoredErrors>
     */
    private function ignoredErrors(Workspace $workspace, array $lists): array
    {
        $scratch = ScratchDirectory::create('intakt-ratchet');

        if (!$scratch instanceof ScratchDirectory) {
            return array_map(static fn (): IgnoredErrors => new IgnoredErrors([], ['cannot create a scratch directory']), $lists);
        }

        try {
            return $this->reader->read($workspace, $scratch, $lists);
        } finally {
            $scratch->remove();
        }
    }

    /**
     * @param array<string, IgnoredErrors> $read
     * @param list<string>                 $paths
     *
     * @return list<string>
     */
    private function shrinkFindings(array $read, array $paths): array
    {
        $findings = [];

        foreach ($paths as $path) {
            $head = $read[self::HEAD.$path] ?? new IgnoredErrors([], []);
            $base = $read[self::BASE.$path] ?? null;

            array_push($findings, ...array_map(static fn (string $problem): string => $path.': '.$problem, $head->problems));

            if ($base instanceof IgnoredErrors) {
                array_push($findings, ...array_map(static fn (string $problem): string => $path.' on the target branch: '.$problem, $base->problems));
                array_push($findings, ...$this->growthOf($path, $base, $head));
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function growthOf(string $path, IgnoredErrors $base, IgnoredErrors $head): array
    {
        $findings = [];

        foreach ($head->counts as $entry => $count) {
            $before = $base->counts[$entry] ?? null;

            if (null === $before) {
                $findings[] = $path.': entry not on the target branch\'s list (added, or its identifier, path or message pattern changed): '.$entry;
            } elseif ($count > $before) {
                $findings[] = $path.': count rose from '.$before.' to '.$count.': '.$entry;
            }
        }

        return $findings;
    }
}
