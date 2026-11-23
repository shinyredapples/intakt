<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because an entry a change left unneeded would excuse the same wall again later, the change must remove it.
final readonly class UnneededDocsEntries
{
    /**
     * @param array<string, MarkdownPage> $documents
     *
     * @return list<string>
     */
    public function in(DocsRatchetList $list, array $documents): array
    {
        return [...$this->unusedKeys($list, $documents), ...$this->looseCeilings($list, $documents), ...$this->unneededExcuses($list, $documents)];
    }

    /**
     * @param array<string, MarkdownPage> $documents
     *
     * @return list<string>
     */
    private function unusedKeys(DocsRatchetList $list, array $documents): array
    {
        $findings = [];

        foreach (array_keys($list->paragraphs) as $page) {
            $unused = $this->unusedKeysOf($list, $page, $documents[$page] ?? null);

            if ($unused > 0) {
                $findings[] = DocsRatchetList::PATH.': unneeded paragraph keys of '.$page.': '.$unused.' match no oversized paragraph any more; remove them from the list';
            }
        }

        return $findings;
    }

    private function unusedKeysOf(DocsRatchetList $list, string $page, ?MarkdownPage $document): int
    {
        $oversized = $this->oversizedKeysOf($document);
        $unused = 0;

        foreach (array_unique($list->paragraphs[$page]) as $key) {
            $unused += max(0, $list->allowanceOf($page, $key) - count(array_keys($oversized, $key, true)));
        }

        return $unused;
    }

    /**
     * @return list<string>
     */
    private function oversizedKeysOf(?MarkdownPage $page): array
    {
        $keys = [];

        foreach ($page?->paragraphs() ?? [] as $paragraph) {
            if ($paragraph->words() > DocsGate::PARAGRAPH_WORDS) {
                $keys[] = $paragraph->key();
            }
        }

        return $keys;
    }

    /**
     * @param array<string, MarkdownPage> $documents
     *
     * @return list<string>
     */
    private function looseCeilings(DocsRatchetList $list, array $documents): array
    {
        $findings = [];

        foreach ($list->pages as $page => $ceiling) {
            $words = ($documents[$page] ?? null)?->words() ?? 0;

            if ($words <= DocsGate::PAGE_WORDS) {
                $findings[] = DocsRatchetList::PATH.': '.$page.' is within the page limit now; remove its ceiling from the list';
            } elseif ($words < $ceiling) {
                $findings[] = DocsRatchetList::PATH.': '.$page.' has '.$words.' words now; lower its ceiling from '.$ceiling.' to '.$words;
            }
        }

        return $findings;
    }

    /**
     * @param array<string, MarkdownPage> $documents
     *
     * @return list<string>
     */
    private function unneededExcuses(DocsRatchetList $list, array $documents): array
    {
        $unneeded = array_filter($list->summaries, static fn (string $page): bool => true !== ($documents[$page] ?? null)?->breaksTheSummaryRule());

        return array_values(array_map(static fn (string $page): string => DocsRatchetList::PATH.': '.$page.' no longer needs its "In short" excuse; remove it from the list', $unneeded));
    }
}
