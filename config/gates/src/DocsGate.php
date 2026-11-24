<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsGate implements Gate
{
    // Because a target branch with this file runs the gate, a list missing there was deleted, not yet to come.
    public const string SOURCE = 'config/gates/src/DocsGate.php';

    public function __construct(
        private MarkdownReader $reader,
        private DocsLimits $limits,
    ) {
    }

    public function name(): string
    {
        return 'docs';
    }

    public function run(Workspace $workspace): GateResult
    {
        try {
            $list = $this->listOf($workspace);
            $pages = $this->pagesOf($workspace);
            $base = $this->baseListOf($workspace);
        } catch (DocsRatchetListUnusable|MarkdownReadFailure|ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $findings = [...$this->limitFindings($pages, $list), ...new UnneededDocsEntries($this->limits)->in($list, $pages)];

        if ($base instanceof DocsRatchetList) {
            array_push($findings, ...$list->growthOver($base));
        }

        return $this->resultOf($findings, $base, $workspace);
    }

    private function listOf(Workspace $workspace): DocsRatchetList
    {
        $file = $workspace->root.'/'.DocsRatchetList::PATH;

        if (!is_file($file)) {
            throw new DocsRatchetListUnusable(DocsRatchetList::PATH.' is missing; keep it in place, with empty lists when nothing is listed');
        }

        $json = is_readable($file) ? file_get_contents($file) : false;

        if (false === $json) {
            throw new DocsRatchetListUnusable('cannot read '.DocsRatchetList::PATH);
        }

        return DocsRatchetList::fromJson($json) ?? throw new DocsRatchetListUnusable(DocsRatchetList::PATH.' is not valid: it holds exactly "paragraphs" (each page to a list of 16-hex-character paragraph keys), "pages" (each page to a word ceiling above '.DocsLimits::PAGE_WORDS.') and "summaries" (a list of pages), even when empty');
    }

    /**
     * @return array<string, MarkdownPage>
     */
    private function pagesOf(Workspace $workspace): array
    {
        $paths = array_values(array_filter($workspace->repositoryFiles(), static fn (string $path): bool => is_file($workspace->root.'/'.$path)));
        $pages = [];

        foreach ($this->reader->pages($workspace->root, $paths) as $page) {
            $pages[$page->path] = $page;
        }

        return $pages;
    }

    private function baseListOf(Workspace $workspace): ?DocsRatchetList
    {
        if (!$workspace->hasGit()) {
            return null;
        }

        $mergeBase = $workspace->mergeBase() ?? throw new ScopeFailure('no base branch found, pass --base=<ref>');
        $shown = $workspace->git(['show', $mergeBase.':'.DocsRatchetList::PATH]);

        if ($shown->succeeded()) {
            return DocsRatchetList::fromJson($shown->output) ?? throw new DocsRatchetListUnusable(DocsRatchetList::PATH.' on the target branch is not valid; fix it there first');
        }

        if ($workspace->git(['cat-file', '-e', $mergeBase.':'.self::SOURCE])->succeeded()) {
            throw new DocsRatchetListUnusable(DocsRatchetList::PATH.' is missing on the target branch, which runs the docs gate; restore it there first');
        }

        return null;
    }

    /**
     * @param array<string, MarkdownPage> $pages
     *
     * @return list<string>
     */
    private function limitFindings(array $pages, DocsRatchetList $list): array
    {
        $findings = [];

        foreach ($pages as $path => $page) {
            array_push($findings, ...$this->paragraphFindings($page, $list), ...$this->pageFindings($page, $list));

            $problem = $list->excusesSummary($path) ? null : $this->limits->summaryProblemOf($page);

            if (null !== $problem) {
                $findings[] = $path.': '.$problem;
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $findings
     */
    private function resultOf(array $findings, ?DocsRatchetList $base, Workspace $workspace): GateResult
    {
        return match (true) {
            [] !== $findings => GateResult::withFindings($this->name(), $findings),
            $base instanceof DocsRatchetList => GateResult::passed($this->name()),
            $workspace->hasGit() => GateResult::passedBecause($this->name(), 'list not compared with the target branch, which has no '.DocsRatchetList::PATH.' yet'),
            default => GateResult::passedBecause($this->name(), 'list not compared with the target branch, no git history in this tree'),
        };
    }

    /**
     * @return list<string>
     */
    private function paragraphFindings(MarkdownPage $page, DocsRatchetList $list): array
    {
        $findings = [];
        $seen = [];

        foreach ($this->limits->oversizedParagraphsOf($page) as $paragraph) {
            $key = $paragraph->key();
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            if ($seen[$key] > $list->allowanceOf($page->path, $key)) {
                $findings[] = $page->path.':'.$paragraph->line.': paragraph of '.$paragraph->words.' words, the limit is '.DocsLimits::PARAGRAPH_WORDS.' words; split it into shorter paragraphs or move the detail to docs/reference/';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function pageFindings(MarkdownPage $page, DocsRatchetList $list): array
    {
        if (!$this->limits->limitsThePage($page->path)) {
            return [];
        }

        $words = $page->words();
        $ceiling = $list->ceilingOf($page->path);

        return match (true) {
            null === $ceiling && $words > DocsLimits::PAGE_WORDS => [$page->path.': page of '.$words.' words, the limit is '.DocsLimits::PAGE_WORDS.' words for pages under docs/user/ and docs/guide/; split it into shorter pages or move the detail to docs/reference/'],
            null !== $ceiling && $words > $ceiling => [$page->path.': page of '.$words.' words, above its ceiling of '.$ceiling.' in '.DocsRatchetList::PATH.' (the limit is '.DocsLimits::PAGE_WORDS.' words); shorten it, split it into pages or move the detail to docs/reference/'],
            default => [],
        };
    }
}
