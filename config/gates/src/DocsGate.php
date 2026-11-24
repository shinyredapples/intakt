<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsGate implements Gate
{
    private const string SHRINK_ONLY = '; this list of oversized paragraphs and pages may only shrink, restore it from the target branch';

    public function __construct(
        private MarkdownReader $reader,
        private DocsLimits $limits = new DocsLimits(),
    ) {
    }

    public function name(): string
    {
        return 'docs';
    }

    public function run(Workspace $workspace): GateResult
    {
        $file = $workspace->root.'/'.DocsRatchetList::PATH;

        if (!is_file($file)) {
            return GateResult::failedBecause($this->name(), DocsRatchetList::PATH.': missing'.self::SHRINK_ONLY);
        }

        $list = DocsRatchetList::fromJson((string) file_get_contents($file));

        if (!$list instanceof DocsRatchetList) {
            return GateResult::failedBecause($this->name(), DocsRatchetList::PATH.': malformed, expected '.DocsRatchetList::SHAPE.' with each page a path ending in .md and each count a positive whole number');
        }

        if ($list->isEmpty()) {
            return GateResult::failedBecause($this->name(), DocsRatchetList::PATH.': empty'.self::SHRINK_ONLY);
        }

        return $this->checked($workspace, $list);
    }

    private function checked(Workspace $workspace, DocsRatchetList $list): GateResult
    {
        try {
            $pages = $this->pagesOf($workspace);
        } catch (MarkdownReadFailure|ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        // The stale entries need only the list and the pages, so they are checked also before the target branch has a list.
        $findings = [...$this->limitFindings($list, $pages), ...new UnneededDocsEntries($this->limits)->in($list, $pages)];

        if (!$workspace->hasGit()) {
            return [] === $findings
                ? GateResult::passedBecause($this->name(), 'target branch comparison skipped, no git history in this tree')
                : GateResult::withFindings($this->name(), $findings);
        }

        $findings = [...$findings, ...$this->targetBranchFindings($workspace, $list)];

        return [] === $findings ? GateResult::passed($this->name()) : GateResult::withFindings($this->name(), $findings);
    }

    /**
     * @return array<string, MarkdownPage>
     */
    private function pagesOf(Workspace $workspace): array
    {
        $files = array_values(array_filter(
            $workspace->repositoryFiles(),
            static fn (string $path): bool => is_file($workspace->root.'/'.$path) && !is_link($workspace->root.'/'.$path),
        ));
        $pages = [];

        foreach ($this->reader->pages($workspace->root, $files) as $page) {
            $pages[$page->path] = $page;
        }

        return $pages;
    }

    /**
     * @param array<string, MarkdownPage> $pages
     *
     * @return list<string>
     */
    private function limitFindings(DocsRatchetList $list, array $pages): array
    {
        $findings = [];

        foreach ($pages as $path => $page) {
            $allowed = [];

            // A listed key allows only its first paragraph on the page, so copying an oversized paragraph cannot add violations.
            foreach ($page->paragraphs() as $paragraph) {
                $key = $paragraph->key();
                $findings[] = $this->paragraphFinding($path, $paragraph, isset($allowed[$key]) ? null : $list->paragraphCeiling($path, $key));
                $allowed[$key] = true;
            }

            $findings[] = $this->limits->limitsPage($path) ? $this->pageFinding($path, $page->words(), $list->pageCeiling($path)) : '';
        }

        return array_values(array_filter($findings, static fn (string $finding): bool => '' !== $finding));
    }

    /**
     * @return list<string>
     */
    private function targetBranchFindings(Workspace $workspace, DocsRatchetList $list): array
    {
        $mergeBase = $workspace->mergeBase();

        if (null === $mergeBase) {
            return ['no base branch found, pass --base=<ref>'];
        }

        try {
            $baseList = $this->baseListOf($workspace, $mergeBase);
        } catch (ScopeFailure $failure) {
            return [DocsRatchetList::PATH.' on the target branch: unreadable, so the change cannot be compared with it ('.$failure->getMessage().'); fetch the target branch again'];
        }

        // Because the list arrives with its gate, a target branch without one has nothing to compare with.
        if (null === $baseList) {
            return [];
        }

        $base = DocsRatchetList::fromJson($baseList);

        if (!$base instanceof DocsRatchetList) {
            return [DocsRatchetList::PATH.' on the target branch: malformed, so the change cannot be compared with it; fix the list on the target branch first'];
        }

        return [...$this->paragraphGrowth($base, $list), ...$this->pageGrowth($base, $list)];
    }

    private function paragraphFinding(string $path, MarkdownParagraph $paragraph, ?int $ceiling): string
    {
        $words = $paragraph->words;

        if ($words <= DocsLimits::PARAGRAPH_WORDS || (null !== $ceiling && $words <= $ceiling)) {
            return '';
        }

        $where = $path.':'.$paragraph->line.': paragraph of '.$words.' words, over ';

        return null === $ceiling
            ? $where.'the limit of '.DocsLimits::PARAGRAPH_WORDS.' words per paragraph; split it or cut words'
            : $where.'its ceiling of '.$ceiling.' words in '.DocsRatchetList::PATH.' and the limit of '.DocsLimits::PARAGRAPH_WORDS.' words per paragraph; split it or cut words to at most '.$ceiling;
    }

    private function pageFinding(string $path, int $words, ?int $ceiling): string
    {
        if ($words <= DocsLimits::PAGE_WORDS || (null !== $ceiling && $words <= $ceiling)) {
            return '';
        }

        $where = $path.': page of '.$words.' words, over ';

        return null === $ceiling
            ? $where.'the limit of '.DocsLimits::PAGE_WORDS.' words per page in '.$this->limits->limitedFolders().'; split it into pages or cut words'
            : $where.'its ceiling of '.$ceiling.' words in '.DocsRatchetList::PATH.' and the limit of '.DocsLimits::PAGE_WORDS.' words per page; cut it to at most '.$ceiling.' words';
    }

    // Because a failed read must not pass as a missing list, the tree is listed first and only a list it lacks counts as absent.
    private function baseListOf(Workspace $workspace, string $mergeBase): ?string
    {
        $listed = $workspace->git(['ls-tree', '--name-only', $mergeBase, '--', DocsRatchetList::PATH]);

        if (!$listed->succeeded()) {
            throw new ScopeFailure($listed->firstErrorLine());
        }

        if ('' === trim($listed->output)) {
            return null;
        }

        $shown = $workspace->git(['show', $mergeBase.':'.DocsRatchetList::PATH]);

        if (!$shown->succeeded()) {
            throw new ScopeFailure($shown->firstErrorLine());
        }

        return $shown->output;
    }

    /**
     * @return list<string>
     */
    private function paragraphGrowth(DocsRatchetList $base, DocsRatchetList $head): array
    {
        $findings = [];

        foreach ($head->paragraphs as $path => $keys) {
            foreach ($keys as $key => $words) {
                $entry = DocsRatchetList::PATH.': paragraph '.$key.' of '.$path;
                $before = $base->paragraphCeiling($path, (string) $key);

                if (null === $before) {
                    $findings[] = $entry.' is not on the target branch\'s list; the list may only shrink, so bring the paragraph within '.DocsLimits::PARAGRAPH_WORDS.' words instead';
                } elseif ($words > $before) {
                    $findings[] = $entry.' rose from '.$before.' to '.$words.' words; the list may only shrink, so cut the paragraph to at most '.$before.' words instead';
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function pageGrowth(DocsRatchetList $base, DocsRatchetList $head): array
    {
        $findings = [];

        foreach ($head->pages as $path => $words) {
            $entry = DocsRatchetList::PATH.': page '.$path;
            $before = $base->pageCeiling($path);

            if (null === $before) {
                $findings[] = $entry.' is not on the target branch\'s list; the list may only shrink, so cut the page to at most '.DocsLimits::PAGE_WORDS.' words instead';
            } elseif ($words > $before) {
                $findings[] = $entry.' rose from '.$before.' to '.$words.' words; the list may only shrink, so cut the page to at most '.$before.' words instead';
            }
        }

        return $findings;
    }
}
