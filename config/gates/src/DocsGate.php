<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsGate implements Gate
{
    // Because a replay over main's merges of 2026-11-09 to 2026-11-23 bounced 2 merges at 80 words (3 at 60) only for editing a wall
    // without growing it, against 191 that added or grew one: 80 keeps the "about 60" rule with margin (docs/architecture/quality-gates.md).
    public const int PARAGRAPH_WORDS = 80;
    public const int PAGE_WORDS = 1500;
    public const int SUMMARY_WORDS = 120;
    public const int SUMMARY_THRESHOLD_WORDS = 1500;

    // Because a target branch with this file runs the gate, a list missing there was deleted, not yet to come.
    public const string SOURCE = 'config/gates/src/DocsGate.php';

    private const array PAGE_LIMITED_FOLDERS = ['docs/user/', 'docs/guide/'];

    public function name(): string
    {
        return 'docs';
    }

    public function run(Workspace $workspace): GateResult
    {
        $file = $workspace->root.'/'.DocsRatchetList::PATH;

        if (!is_file($file)) {
            return GateResult::failedBecause($this->name(), DocsRatchetList::PATH.' is missing; keep it in place, with empty lists when nothing is listed');
        }

        $json = file_get_contents($file);
        $list = false === $json ? null : DocsRatchetList::fromJson($json);

        if (!$list instanceof DocsRatchetList) {
            return GateResult::failedBecause($this->name(), DocsRatchetList::PATH.' is not valid: it needs "paragraphs", "pages" and "summaries"');
        }

        try {
            $documents = $this->documentsOf($workspace);
            $base = $this->baseListOf($workspace);
        } catch (ScopeFailure $failure) {
            return GateResult::failedBecause($this->name(), $failure->getMessage());
        }

        $findings = $this->limitFindings($documents, $list);

        if ($base instanceof DocsRatchetList) {
            array_push($findings, ...$list->growthOver($base), ...$list->staleEntries($documents, $this->oversizedKeysOf($documents), self::PAGE_WORDS, self::SUMMARY_THRESHOLD_WORDS));
        }

        if ([] !== $findings) {
            return GateResult::withFindings($this->name(), $findings);
        }

        return match (true) {
            $base instanceof DocsRatchetList => GateResult::passed($this->name()),
            $workspace->hasGit() => GateResult::passedBecause($this->name(), 'ratchet checks skipped, the target branch has no docs gate yet'),
            default => GateResult::passedBecause($this->name(), 'ratchet checks skipped, no git history in this tree'),
        };
    }

    /**
     * @return array<string, MarkdownPage>
     */
    private function documentsOf(Workspace $workspace): array
    {
        $documents = [];

        foreach ($workspace->repositoryFiles() as $path) {
            if (!$this->isDocument($path) || !is_file($workspace->root.'/'.$path)) {
                continue;
            }

            $text = file_get_contents($workspace->root.'/'.$path);

            if (false === $text) {
                throw new ScopeFailure('cannot read '.$path);
            }

            $documents[$path] = new MarkdownPage($text);
        }

        return $documents;
    }

    private function isDocument(string $path): bool
    {
        return str_ends_with($path, '.md') && ('README.md' === $path || str_starts_with($path, 'docs/'));
    }

    /**
     * @param array<string, MarkdownPage> $documents
     *
     * @return list<string>
     */
    private function limitFindings(array $documents, DocsRatchetList $list): array
    {
        $findings = [];

        foreach ($documents as $path => $page) {
            array_push(
                $findings,
                ...$this->paragraphFindings($path, $page, $list),
                ...$this->pageLengthFindings($path, $page, $list),
                ...$this->summaryFindings($path, $page, $list),
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function paragraphFindings(string $path, MarkdownPage $page, DocsRatchetList $list): array
    {
        $findings = [];
        $seen = [];

        foreach ($this->oversizedParagraphsOf($page) as $paragraph) {
            $key = $paragraph->key();
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            if ($seen[$key] > $list->allowanceOf($path, $key)) {
                $findings[] = $path.':'.$paragraph->line.': paragraph of '.$paragraph->words().' words, the limit is '.self::PARAGRAPH_WORDS.'; split it into short paragraphs or move the detail to docs/reference/';
            }
        }

        return $findings;
    }

    /**
     * @param array<string, MarkdownPage> $documents
     *
     * @return array<string, array<string, int>>
     */
    private function oversizedKeysOf(array $documents): array
    {
        $keys = [];

        foreach ($documents as $path => $page) {
            foreach ($this->oversizedParagraphsOf($page) as $paragraph) {
                $keys[$path][$paragraph->key()] = ($keys[$path][$paragraph->key()] ?? 0) + 1;
            }
        }

        return $keys;
    }

    /**
     * @return list<MarkdownParagraph>
     */
    private function oversizedParagraphsOf(MarkdownPage $page): array
    {
        return array_values(array_filter($page->paragraphs(), static fn (MarkdownParagraph $paragraph): bool => $paragraph->words() > self::PARAGRAPH_WORDS));
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
            return [$path.': a page over '.self::SUMMARY_THRESHOLD_WORDS.' words opens with an "In short" section of at most '.self::SUMMARY_WORDS.' words; add it right after the title, with no text between'];
        }

        if ($page->summaryWords() > self::SUMMARY_WORDS) {
            return [$path.': the "In short" section has '.$page->summaryWords().' words, the limit is '.self::SUMMARY_WORDS.'; shorten it and move the rest below its own heading'];
        }

        return [];
    }

    private function baseListOf(Workspace $workspace): ?DocsRatchetList
    {
        if (!$workspace->hasGit()) {
            return null;
        }

        $mergeBase = $workspace->mergeBase();

        if (null === $mergeBase) {
            throw new ScopeFailure('no base branch found, pass --base=<ref>');
        }

        $shown = $workspace->git(['show', $mergeBase.':'.DocsRatchetList::PATH]);

        if (!$shown->succeeded()) {
            if ($workspace->git(['cat-file', '-e', $mergeBase.':'.self::SOURCE])->succeeded()) {
                throw new ScopeFailure(DocsRatchetList::PATH.' is missing on the target branch, which runs the docs gate; restore it there first');
            }

            return null;
        }

        $base = DocsRatchetList::fromJson($shown->output);

        if (!$base instanceof DocsRatchetList) {
            throw new ScopeFailure(DocsRatchetList::PATH.' on the target branch is not valid');
        }

        return $base;
    }
}
