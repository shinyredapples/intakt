<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsListChanges
{
    private const string LIST = DocsRatchetList::PATH.': ';

    /**
     * @param array<string, DocsPage> $pages
     *
     * @return list<string>
     */
    public function since(DocsRatchetList $base, DocsRatchetList $head, array $pages): array
    {
        return [
            ...$this->grownParagraphs($base, $head),
            ...$this->droppedParagraphs($base, $head, $pages),
            ...$this->grownCeilings('page', $base->pages, $head->pages),
            ...$this->grownCeilings('summary', $base->summaries, $head->summaries),
            ...$this->droppedCeilings('page', $base->pages, $head->pages, $this->wordsOf(array_filter($pages, static fn (DocsPage $page): bool => $page->isOverPageLimit()))),
            ...$this->droppedCeilings('summary', $base->summaries, $head->summaries, $this->wordsOf(array_filter($pages, static fn (DocsPage $page): bool => null !== $page->summaryProblem))),
        ];
    }

    /**
     * @return list<string>
     */
    private function grownParagraphs(DocsRatchetList $base, DocsRatchetList $head): array
    {
        $findings = [];

        foreach ($head->paragraphs as $page => $keys) {
            foreach ($keys as $key => $count) {
                $before = $base->paragraphCount($page, $key);

                if ($count > $before) {
                    $findings[] = self::LIST.'paragraph '.$key.' of '.$page.' rose from '.$before.' to '.$count.', but the list may only shrink: bring the paragraph within '.DocsPage::PARAGRAPH_WORDS.' words instead';
                }
            }
        }

        return $findings;
    }

    // Because the target branch excuses its own walls anyway, an entry dropped while its wall stays would only show in a tree without git history.
    /**
     * @param array<string, DocsPage> $pages
     *
     * @return list<string>
     */
    private function droppedParagraphs(DocsRatchetList $base, DocsRatchetList $head, array $pages): array
    {
        $findings = [];

        foreach ($base->paragraphs as $page => $keys) {
            foreach ($keys as $key => $count) {
                $needed = min($count, ($pages[$page] ?? null)?->oversizedCounts[$key] ?? 0);

                if ($head->paragraphCount($page, $key) < $needed) {
                    $findings[] = self::LIST.'paragraph '.$key.' of '.$page.' was dropped from the list while the page still has it: restore its count of '.$needed;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     *
     * @return list<string>
     */
    private function grownCeilings(string $kind, array $before, array $after): array
    {
        $findings = [];

        foreach ($after as $page => $ceiling) {
            if ($ceiling > ($before[$page] ?? 0)) {
                $findings[] = self::LIST.'the '.$kind.' ceiling of '.$page.' rose from '.($before[$page] ?? 0).' to '.$ceiling.', but the list may only shrink: cut the page instead';
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
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @param array<string, int> $needing the words of each page that still needs its entry
     *
     * @return list<string>
     */
    private function droppedCeilings(string $kind, array $before, array $after, array $needing): array
    {
        $findings = [];

        foreach ($before as $page => $ceiling) {
            $needed = min($ceiling, $needing[$page] ?? 0);

            if (($after[$page] ?? 0) < $needed) {
                $findings[] = self::LIST.'the '.$kind.' ceiling of '.$page.' was dropped or lowered below the page: restore it to '.$needed;
            }
        }

        return $findings;
    }
}
