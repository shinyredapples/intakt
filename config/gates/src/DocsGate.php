<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsGate implements Gate
{
    // Because a first run has no list to compare with, the gate's own file on the target branch tells it from a list that went missing.
    private const string GATE_FILE = 'config/gates/src/DocsGate.php';

    public function __construct(
        private MarkdownReader $reader,
        private UnneededDocsEntries $unneeded = new UnneededDocsEntries(),
        private DocsListChanges $changes = new DocsListChanges(),
    ) {
    }

    public function name(): string
    {
        return 'docs';
    }

    public function run(Workspace $workspace): GateResult
    {
        try {
            $list = $this->headListOf($workspace);
            $pages = $this->pagesOf($workspace);
            $target = $this->targetOf($workspace);
            $base = null === $target ? null : $this->baseListOf($workspace, $target);
            $allowances = new DocsAllowances($list, null === $target ? [] : $this->targetPagesOf($workspace, $target, $pages));
        } catch (DocsListInvalid|MarkdownReadFailure|ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $findings = [
            ...$this->documentFindings($pages, $allowances),
            ...($base instanceof DocsRatchetList ? $this->changes->since($base, $list, $pages) : []),
            ...$this->unneeded->in($list, $pages),
        ];

        if ([] !== $findings) {
            return GateResult::withFindings($this->name(), $findings);
        }

        return null === $target ? GateResult::passedBecause($this->name(), 'checked against the list alone, no git history in this tree') : GateResult::passed($this->name());
    }

    private function headListOf(Workspace $workspace): DocsRatchetList
    {
        $file = $workspace->root.'/'.DocsRatchetList::PATH;
        $json = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        if (false === $json) {
            throw new DocsListInvalid(DocsRatchetList::PATH.' is missing or unreadable: the docs gate needs its list of the walls the documentation already has; restore it from the target branch');
        }

        return $this->listFrom($json, DocsRatchetList::PATH);
    }

    /**
     * @return array<string, DocsPage>
     */
    private function pagesOf(Workspace $workspace): array
    {
        $pages = [];
        // Because the index still lists a page deleted but not yet staged, a page the working tree confirms missing is skipped; any other page goes to the reader, which fails on one it cannot read.
        $present = array_filter($workspace->repositoryFiles(), fn (string $path): bool => !$this->isMissing($workspace->root, $path));

        foreach ($this->reader->pages($workspace->root, array_values($present)) as $page) {
            $pages[$page->path] = new DocsPage($page);
        }

        return $pages;
    }

    // Because a folder that cannot be searched hides its entries from file_exists(), absence counts only where the nearest existing folder can be searched.
    private function isMissing(string $root, string $path): bool
    {
        $file = $root.'/'.$path;

        if (file_exists($file) || is_link($file)) {
            return false;
        }

        $parent = dirname($path);
        $folder = '.' === $parent ? $root : $root.'/'.$parent;

        if (is_dir($folder)) {
            return is_executable($folder);
        }

        return '.' !== $parent && (file_exists($folder) || $this->isMissing($root, $parent));
    }

    private function targetOf(Workspace $workspace): ?string
    {
        if (!$workspace->hasGit()) {
            return null;
        }

        return $workspace->mergeBase() ?? throw new ScopeFailure('no base branch found, pass --base=<ref>');
    }

    private function baseListOf(Workspace $workspace, string $target): ?DocsRatchetList
    {
        $json = $this->fileAt($workspace, $target, DocsRatchetList::PATH);

        if (null !== $json) {
            return $this->listFrom($json, DocsRatchetList::PATH.' on the target branch');
        }

        if (null !== $this->fileAt($workspace, $target, self::GATE_FILE)) {
            throw new DocsListInvalid(DocsRatchetList::PATH.' is missing on the target branch, which runs the docs gate: restore it there first');
        }

        return null;
    }

    /**
     * @param array<string, DocsPage> $pages
     *
     * @return array<string, DocsPage>
     */
    private function targetPagesOf(Workspace $workspace, string $target, array $pages): array
    {
        $targetPages = [];

        foreach (array_filter($pages, static fn (DocsPage $page): bool => $page->needsAllowance()) as $path => $page) {
            $contents = $this->fileAt($workspace, $target, $path);

            if (null !== $contents) {
                $targetPages[$path] = new DocsPage($this->reader->pageFrom($path, $contents));
            }
        }

        return $targetPages;
    }

    /**
     * @param array<string, DocsPage> $pages
     *
     * @return list<string>
     */
    private function documentFindings(array $pages, DocsAllowances $allowances): array
    {
        $findings = [];

        foreach ($pages as $page) {
            array_push($findings, ...$this->paragraphFindings($page, $allowances), ...$this->pageFindings($page, $allowances), ...$this->summaryFindings($page, $allowances));
        }

        return $findings;
    }

    private function listFrom(string $json, string $name): DocsRatchetList
    {
        try {
            return DocsRatchetList::parsed($json);
        } catch (DocsListInvalid $invalid) {
            throw new DocsListInvalid($name.' '.$invalid->getMessage().'; restore it from the target branch', $invalid->getCode(), previous: $invalid);
        }
    }

    // Because a failed read must not pass for an absent file, which would let a list that exists go unchecked, absence is asked of the tree first and any read after that must succeed.
    private function fileAt(Workspace $workspace, string $commit, string $path): ?string
    {
        $listed = $workspace->git(['ls-tree', '--name-only', $commit, '--', $path]);

        if (!$listed->succeeded()) {
            throw new ScopeFailure('cannot list '.$path.' on the target branch: git ls-tree failed');
        }

        if ('' === trim($listed->output)) {
            return null;
        }

        $shown = $workspace->git(['show', $commit.':'.$path]);

        return $shown->succeeded() ? $shown->output : throw new ScopeFailure('cannot read '.$path.' on the target branch: git show failed');
    }

    /**
     * @return list<string>
     */
    private function paragraphFindings(DocsPage $page, DocsAllowances $allowances): array
    {
        $findings = [];
        $seen = [];

        foreach ($page->oversizedParagraphs as $paragraph) {
            $key = $paragraph->key();
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            // The copies past the allowance are the new ones, so the earlier copies on the page stay excused.
            if ($seen[$key] > $allowances->paragraphsOf($page->path, $key)) {
                $findings[] = $page->path.':'.$paragraph->line.': paragraph of '.$paragraph->words.' words, over the limit of '.DocsPage::PARAGRAPH_WORDS.' words: split it or cut it, one idea per paragraph';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function pageFindings(DocsPage $page, DocsAllowances $allowances): array
    {
        if (!$page->isOverPageLimit()) {
            return [];
        }

        $ceiling = $allowances->pageCeilingOf($page->path);
        $limit = 'the limit of '.DocsPage::PAGE_WORDS.' words for a page under '.DocsPage::LIMITED_PAGES;

        if (null === $ceiling) {
            return [$page->path.': page of '.$page->words.' words, over '.$limit.': split it into pages, or move detail only contributors need to docs/reference/ or docs/architecture/'];
        }

        return $page->words > $ceiling ? [$page->path.': page of '.$page->words.' words grew past its ceiling of '.$ceiling.' (over '.$limit.'): cut it back to at most '.$ceiling.' words'] : [];
    }

    /**
     * @return list<string>
     */
    private function summaryFindings(DocsPage $page, DocsAllowances $allowances): array
    {
        if (null === $page->summaryProblem || $allowances->excusesSummary($page)) {
            return [];
        }

        return [$page->path.': page of '.$page->words.' words, over the limit of '.DocsPage::SUMMARY_PAGE_WORDS.' words for a page under docs/architecture/, '.$page->summaryProblem.': open it with an "In short" section of at most '.DocsPage::SUMMARY_WORDS.' words'];
    }
}
