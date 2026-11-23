<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class UnneededDocsEntries
{
    private const string LIST = DocsRatchetList::PATH.': ';

    /**
     * @param array<string, DocsPage> $pages
     *
     * @return list<string>
     */
    public function in(DocsRatchetList $list, array $pages): array
    {
        return [
            ...$this->staleParagraphs($list, $pages),
            ...$this->looseCeilings('page', $list->pages, $this->wordsOf(array_filter($pages, static fn (DocsPage $page): bool => $page->isOverPageLimit()))),
            ...$this->looseCeilings('summary', $list->summaries, $this->wordsOf(array_filter($pages, static fn (DocsPage $page): bool => null !== $page->summaryProblem))),
        ];
    }

    /**
     * @param array<string, DocsPage> $pages
     *
     * @return list<string>
     */
    private function staleParagraphs(DocsRatchetList $list, array $pages): array
    {
        $findings = [];

        foreach ($list->paragraphs as $page => $keys) {
            foreach ($keys as $key => $count) {
                $actual = ($pages[$page] ?? null)?->oversizedCounts[$key] ?? 0;

                if (0 === $actual) {
                    $findings[] = self::LIST.'paragraph '.$key.' of '.$page.' is gone or within '.DocsPage::PARAGRAPH_WORDS.' words: remove its entry';
                } elseif ($count > $actual) {
                    $findings[] = self::LIST.'paragraph '.$key.' of '.$page.' is counted '.$count.' times but the page has it '.$actual.' times: lower the count to '.$actual;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, DocsPage> $pages
     *
     * @return array<string, int>
     */
    private function wordsOf(array $pages): array
    {
        return array_map(static fn (DocsPage $page): int => $page->words, $pages);
    }

    /**
     * @param array<string, int> $ceilings
     * @param array<string, int> $needing  the words of each page that still needs its entry
     *
     * @return list<string>
     */
    private function looseCeilings(string $kind, array $ceilings, array $needing): array
    {
        $findings = [];

        foreach ($ceilings as $page => $ceiling) {
            $words = $needing[$page] ?? null;

            if (null === $words) {
                $findings[] = self::LIST.'the '.$kind.' ceiling of '.$page.' is no longer needed (the page is gone or meets the '.$kind.' rule): remove its entry';
            } elseif ($ceiling > $words) {
                $findings[] = self::LIST.'the '.$kind.' ceiling of '.$page.' is '.$ceiling.' words but the page has '.$words.': lower it to '.$words;
            }
        }

        return $findings;
    }
}
