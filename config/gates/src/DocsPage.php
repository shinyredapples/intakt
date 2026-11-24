<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsPage
{
    // Because a replay over main and two weeks of merged pull requests chose these limits, docs/architecture/quality-gates.md gives its figures.
    public const int PARAGRAPH_WORDS = 80;
    public const int PAGE_WORDS = 1500;
    public const int SUMMARY_PAGE_WORDS = 1500;
    public const int SUMMARY_WORDS = 120;
    public const string LIMITED_PAGES = 'docs/user/ and docs/guide/';
    private const array LIMITED_FOLDERS = ['docs/user/', 'docs/guide/'];
    private const string SUMMARIZED_FOLDER = 'docs/architecture/';

    public string $path;

    public int $words;

    /** @var list<MarkdownParagraph> */
    public array $oversizedParagraphs;

    /** @var array<string, int> */
    public array $oversizedCounts;

    public ?string $summaryProblem;

    public function __construct(MarkdownPage $page)
    {
        $this->path = $page->path;
        $this->words = $page->words();
        $this->oversizedParagraphs = array_values(array_filter($page->paragraphs(), static fn (MarkdownParagraph $paragraph): bool => $paragraph->words > self::PARAGRAPH_WORDS));
        $this->oversizedCounts = array_count_values(array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->key(), $this->oversizedParagraphs));
        $this->summaryProblem = $this->summaryProblemOf($page->summary());
    }

    public function isOverPageLimit(): bool
    {
        return $this->words > self::PAGE_WORDS && array_any(self::LIMITED_FOLDERS, fn (string $folder): bool => str_starts_with($this->path, $folder));
    }

    public function needsAllowance(): bool
    {
        return [] !== $this->oversizedParagraphs || $this->isOverPageLimit() || null !== $this->summaryProblem;
    }

    private function summaryProblemOf(?MarkdownSummary $summary): ?string
    {
        if (!str_starts_with($this->path, self::SUMMARIZED_FOLDER) || $this->words <= self::SUMMARY_PAGE_WORDS) {
            return null;
        }

        return match (true) {
            !$summary instanceof MarkdownSummary => 'has no "In short" section',
            !$summary->opensPage => 'does not open with its "In short" section',
            !$summary->hasText => 'has an empty "In short" section',
            $summary->words > self::SUMMARY_WORDS => 'has an "In short" section of '.$summary->words.' words',
            default => null,
        };
    }
}
