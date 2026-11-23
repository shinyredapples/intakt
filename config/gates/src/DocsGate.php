<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsGate implements Gate
{
    public const int PARAGRAPH_WORDS = 80;
    public const int PAGE_WORDS = 1500;
    public const int SUMMARY_WORDS = 120;
    public const int SUMMARY_THRESHOLD_WORDS = 1500;

    private const array PAGE_LIMITED_FOLDERS = ['docs/user/', 'docs/guide/'];

    public function name(): string
    {
        return 'docs';
    }

    public function run(Workspace $workspace): GateResult
    {
        $list = $this->listAt($workspace->root.'/'.DocsRatchetList::PATH);

        if (!$list instanceof DocsRatchetList) {
            return GateResult::failedBecause($this->name(), DocsRatchetList::PATH.' is not valid: it needs "paragraphs", "pages" and "summaries"');
        }

        try {
            $findings = [...$this->pageFindings($workspace, $list), ...$this->growthFindings($workspace, $list)];
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        if ([] !== $findings) {
            return GateResult::withFindings($this->name(), $findings);
        }

        return $workspace->hasGit() ? GateResult::passed($this->name()) : GateResult::passedBecause($this->name(), 'ratchet shrink check skipped, no git history in this tree');
    }

    private function listAt(string $file): ?DocsRatchetList
    {
        if (!is_file($file)) {
            return DocsRatchetList::empty();
        }

        $json = file_get_contents($file);

        return false === $json ? null : DocsRatchetList::fromJson($json);
    }

    /**
     * @return list<string>
     */
    private function pageFindings(Workspace $workspace, DocsRatchetList $list): array
    {
        $findings = [];

        foreach ($workspace->repositoryFiles() as $path) {
            if (!$this->isDocument($path) || !is_file($workspace->root.'/'.$path)) {
                continue;
            }

            $text = file_get_contents($workspace->root.'/'.$path);

            if (false === $text) {
                throw new ScopeFailure('cannot read '.$path);
            }

            array_push($findings, ...$this->findingsOf($path, new MarkdownPage($text), $list));
        }

        return $findings;
    }

    private function isDocument(string $path): bool
    {
        return str_ends_with($path, '.md') && ('README.md' === $path || str_starts_with($path, 'docs/'));
    }

    /**
     * @return list<string>
     */
    private function findingsOf(string $path, MarkdownPage $page, DocsRatchetList $list): array
    {
        return [
            ...$this->paragraphFindings($path, $page, $list),
            ...$this->pageLengthFindings($path, $page, $list),
            ...$this->summaryFindings($path, $page, $list),
        ];
    }

    /**
     * @return list<string>
     */
    private function paragraphFindings(string $path, MarkdownPage $page, DocsRatchetList $list): array
    {
        $findings = [];

        foreach ($page->paragraphs() as $paragraph) {
            if ($paragraph->words() > self::PARAGRAPH_WORDS && !$list->excusesParagraph($path, $paragraph->key())) {
                $findings[] = $path.':'.$paragraph->line.': paragraph of '.$paragraph->words().' words, the limit is '.self::PARAGRAPH_WORDS.'; split it into short paragraphs or move the detail to docs/reference/';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function pageLengthFindings(string $path, MarkdownPage $page, DocsRatchetList $list): array
    {
        if (!array_any(self::PAGE_LIMITED_FOLDERS, static fn (string $folder): bool => str_starts_with($path, $folder))) {
            return [];
        }

        $limit = $list->ceilingOf($path) ?? self::PAGE_WORDS;

        if ($page->words() <= $limit) {
            return [];
        }

        return [$path.': page of '.$page->words().' words, the limit is '.$limit.'; split it into chapters or move the detail to docs/reference/'];
    }

    /**
     * @return list<string>
     */
    private function summaryFindings(string $path, MarkdownPage $page, DocsRatchetList $list): array
    {
        if (!str_starts_with($path, 'docs/architecture/') || $page->words() <= self::SUMMARY_THRESHOLD_WORDS || $list->excusesSummary($path)) {
            return [];
        }

        if (!$page->hasSummaryFirst()) {
            return [$path.': a page over '.self::SUMMARY_THRESHOLD_WORDS.' words opens with an "In short" section of at most '.self::SUMMARY_WORDS.' words; add it right after the title'];
        }

        if ($page->summaryWords() > self::SUMMARY_WORDS) {
            return [$path.': the "In short" section has '.$page->summaryWords().' words, the limit is '.self::SUMMARY_WORDS.'; shorten it and move the rest below its own heading'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function growthFindings(Workspace $workspace, DocsRatchetList $list): array
    {
        if (!$workspace->hasGit()) {
            return [];
        }

        $mergeBase = $workspace->mergeBase();

        if (null === $mergeBase) {
            throw new ScopeFailure('no base branch found, pass --base=<ref>');
        }

        $shown = $workspace->git(['show', $mergeBase.':'.DocsRatchetList::PATH]);

        // Because the list arrives with the change that switches the gate on, there is nothing to compare it with yet.
        if (!$shown->succeeded()) {
            return [];
        }

        $base = DocsRatchetList::fromJson($shown->output);

        if (!$base instanceof DocsRatchetList) {
            throw new ScopeFailure(DocsRatchetList::PATH.' on the target branch is not valid');
        }

        return $list->growthOver($base);
    }
}
