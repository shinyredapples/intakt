<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsLimits
{
    public const int PARAGRAPH_WORDS = 80;

    // Because a user chapter or guide page is read in one sitting, it stays within the length at which a technical page needs a summary.
    public const int PAGE_WORDS = 1500;

    public const int SUMMARY_WORDS = 120;

    public const int SUMMARY_THRESHOLD_WORDS = 1500;

    private const array PAGE_LIMITED_FOLDERS = ['docs/user/', 'docs/guide/'];

    private const string SUMMARIZED_FOLDER = 'docs/architecture/';

    /**
     * @return list<MarkdownParagraph>
     */
    public function oversizedParagraphsOf(MarkdownPage $page): array
    {
        return array_values(array_filter($page->paragraphs(), static fn (MarkdownParagraph $paragraph): bool => $paragraph->words > self::PARAGRAPH_WORDS));
    }

    public function limitsThePage(string $path): bool
    {
        return array_any(self::PAGE_LIMITED_FOLDERS, static fn (string $folder): bool => str_starts_with($path, $folder));
    }

    public function summaryProblemOf(MarkdownPage $page): ?string
    {
        if (!str_starts_with($page->path, self::SUMMARIZED_FOLDER) || $page->words() <= self::SUMMARY_THRESHOLD_WORDS) {
            return null;
        }

        $summary = $page->summary();

        return match (true) {
            !$summary instanceof MarkdownSummary || !$summary->opensPage => 'a page under '.self::SUMMARIZED_FOLDER.' over '.self::SUMMARY_THRESHOLD_WORDS.' words must open with an "In short" section of at most '.self::SUMMARY_WORDS.' words; add one right after the title, as the page\'s own heading (not quoted, not in a list item)',
            !$summary->holdsText => 'the "In short" section holds no text a reader sees (headings and HTML comments do not count), the limit is at least one word and at most '.self::SUMMARY_WORDS.'; write the summary in it',
            $summary->words > self::SUMMARY_WORDS => 'the "In short" section has '.$summary->words.' words, the limit is '.self::SUMMARY_WORDS.'; shorten it and move the rest to a section of its own',
            default => null,
        };
    }
}
