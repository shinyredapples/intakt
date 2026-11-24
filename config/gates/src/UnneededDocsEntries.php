<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because an entry a change left unneeded would excuse a new wall later, the change that makes it unneeded removes it.
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
        return [...$this->unusedKeys($list, $pages), ...$this->looseCeilings($list, $pages), ...$this->unneededExcuses($list, $pages)];
    }

    /**
     * @param array<string, MarkdownPage> $pages
     *
     * @return list<string>
     */
    private function unusedKeys(DocsRatchetList $list, array $pages): array
    {
        $findings = [];

        foreach (array_keys($list->paragraphs) as $path) {
            $unused = $this->unusedKeysOf($list, $path, $pages[$path] ?? null);

            if ([] !== $unused) {
                $findings[] = DocsRatchetList::PATH.': '.$path.' lists paragraph keys that match no paragraph over '.DocsLimits::PARAGRAPH_WORDS.' words any more ('.implode(', ', $unused).'); remove them from the list';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function unusedKeysOf(DocsRatchetList $list, string $path, ?MarkdownPage $page): array
    {
        $oversized = array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->key(), $page instanceof MarkdownPage ? $this->limits->oversizedParagraphsOf($page) : []);
        $unused = [];

        foreach (array_unique($list->paragraphs[$path]) as $key) {
            if ($list->allowanceOf($path, $key) > count(array_keys($oversized, $key, true))) {
                $unused[] = $key;
            }
        }

        return $unused;
    }

    /**
     * @param array<string, MarkdownPage> $pages
     *
     * @return list<string>
     */
    private function looseCeilings(DocsRatchetList $list, array $pages): array
    {
        $findings = [];

        foreach ($list->pages as $path => $ceiling) {
            $words = ($pages[$path] ?? null)?->words() ?? 0;

            if (!$this->limits->limitsThePage($path) || $words <= DocsLimits::PAGE_WORDS) {
                $findings[] = DocsRatchetList::PATH.': '.$path.' needs no page ceiling (the limit of '.DocsLimits::PAGE_WORDS.' words applies only to pages under docs/user/ and docs/guide/ above it); remove its ceiling from the list';
            } elseif ($words < $ceiling) {
                $findings[] = DocsRatchetList::PATH.': '.$path.' has '.$words.' words now; lower its ceiling from '.$ceiling.' to '.$words;
            }
        }

        return $findings;
    }

    /**
     * @param array<string, MarkdownPage> $pages
     *
     * @return list<string>
     */
    private function unneededExcuses(DocsRatchetList $list, array $pages): array
    {
        $unneeded = array_filter($list->summaries, fn (string $path): bool => !isset($pages[$path]) || null === $this->limits->summaryProblemOf($pages[$path]));

        return array_values(array_map(
            static fn (string $path): string => DocsRatchetList::PATH.': '.$path.' meets the "In short" rule now; remove it from "summaries"',
            $unneeded,
        ));
    }
}
