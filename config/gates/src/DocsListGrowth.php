<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsListGrowth
{
    /**
     * @return list<string>
     */
    public function between(DocsRatchetList $base, DocsRatchetList $head): array
    {
        return [...$this->paragraphGrowth($base, $head), ...$this->pageGrowth($base, $head)];
    }

    /**
     * @return list<string>
     */
    private function paragraphGrowth(DocsRatchetList $base, DocsRatchetList $head): array
    {
        $findings = [];

        foreach ($head->paragraphs as $path => $keys) {
            foreach ($keys as $key => $words) {
                $entry = DocsRatchetList::PATH.': paragraph '.$key.' of '.$path;
                $before = $base->paragraphCeiling($path, (string) $key);

                if (null === $before) {
                    $findings[] = $entry.' is not on the target branch\'s list; the list may only shrink, so bring the paragraph within '.DocsLimits::PARAGRAPH_WORDS.' words instead';
                } elseif ($words > $before) {
                    $findings[] = $entry.' rose from '.$before.' to '.$words.' words; the list may only shrink, so cut the paragraph to at most '.$before.' words instead';
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function pageGrowth(DocsRatchetList $base, DocsRatchetList $head): array
    {
        $findings = [];

        foreach ($head->pages as $path => $words) {
            $entry = DocsRatchetList::PATH.': page '.$path;
            $before = $base->pageCeiling($path);

            if (null === $before) {
                $findings[] = $entry.' is not on the target branch\'s list; the list may only shrink, so cut the page to at most '.DocsLimits::PAGE_WORDS.' words instead';
            } elseif ($words > $before) {
                $findings[] = $entry.' rose from '.$before.' to '.$words.' words; the list may only shrink, so cut the page to at most '.$before.' words instead';
            }
        }

        return $findings;
    }
}
