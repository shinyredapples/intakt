<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class UnneededDocsEntries
{
    public function __construct(private DocsLimits $limits)
    {
    }

    /**
     * @param array<string, MarkdownPage> $pages
     *
     * @return list<string>
     */
    public function in(DocsRatchetList $list, array $pages): array
    {
        $findings = [];

        foreach ($list->paragraphs as $path => $keys) {
            array_push($findings, ...$this->paragraphFindings($path, $keys, $pages[$path] ?? null));
        }

        foreach ($list->pages as $path => $ceiling) {
            $findings[] = $this->pageFinding($path, $ceiling, $pages[$path] ?? null);
        }

        return array_map(static fn (string $finding): string => DocsRatchetList::PATH.': '.$finding, array_values(array_filter($findings)));
    }

    /**
     * @param array<int|string, int> $keys
     *
     * @return list<string>
     */
    private function paragraphFindings(string $path, array $keys, ?MarkdownPage $page): array
    {
        if (!$page instanceof MarkdownPage) {
            return ['page '.$path.' no longer exists; remove its entry'];
        }

        $words = [];

        foreach ($page->paragraphs() as $paragraph) {
            $words[$paragraph->key()] = $paragraph->words;
        }

        $findings = [];

        foreach ($keys as $key => $ceiling) {
            $findings[] = $this->paragraphFinding('paragraph '.$key.' of '.$path, $ceiling, $words[(string) $key] ?? null);
        }

        return array_values(array_filter($findings));
    }

    private function pageFinding(string $path, int $ceiling, ?MarkdownPage $page): string
    {
        if (!$page instanceof MarkdownPage) {
            return 'page '.$path.' no longer exists; remove its entry';
        }

        if (!$this->limits->limitsPage($path)) {
            return 'page '.$path.' has no page limit (only pages under '.$this->limits->limitedFolders().' do); remove its entry';
        }

        $words = $page->words();

        if ($words <= DocsLimits::PAGE_WORDS) {
            return 'page '.$path.' has '.$words.' words, within the limit of '.DocsLimits::PAGE_WORDS.'; remove its entry';
        }

        return $ceiling > $words ? 'page '.$path.' lists '.$ceiling.' words but has '.$words.'; lower it to '.$words : '';
    }

    private function paragraphFinding(string $entry, int $ceiling, ?int $words): string
    {
        if (null === $words) {
            return $entry.' is no longer on the page (changed or removed); remove its key';
        }

        if ($words <= DocsLimits::PARAGRAPH_WORDS) {
            return $entry.' has '.$words.' words, within the limit of '.DocsLimits::PARAGRAPH_WORDS.'; remove its key';
        }

        return $ceiling > $words ? $entry.' lists '.$ceiling.' words but has '.$words.'; lower it to '.$words : '';
    }
}
