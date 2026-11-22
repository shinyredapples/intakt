<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Domain;

use Intakt\Core\ReviewerForLoop\Contract\LoopFinding;
use NoDiscard;

final readonly class ReviewComparison
{
    // Two findings about the same spot rarely agree on the exact line, so a few lines either way still match.
    public const int LINE_WINDOW = 3;

    /**
     * @template L of LoopFinding
     * @template I of LoopFinding
     *
     * @param list<list<L>> $loopReviews
     * @param list<list<I>> $intaktReviews
     *
     * @return array{list<array{L, I}>, list<L>, list<I>}
     */
    #[NoDiscard('a match that is not read reports nothing')]
    public function matchedSpots(array $loopReviews, array $intaktReviews): array
    {
        return $this->match($this->mergedBySpot($loopReviews), $this->mergedBySpot($intaktReviews));
    }

    /**
     * @template T of LoopFinding
     *
     * @param list<list<T>> $reviews
     *
     * @return list<MergedSpot<T>>
     */
    private function mergedBySpot(array $reviews): array
    {
        // Several reviewers on one side often raise the same spot, at different severities or a line apart, so a spot counts once within the same window the match uses. Sorted by file and line, a finding joins the spot before it when the spot admits it, so the spots do not depend on the reviewers' order.
        $spots = [];
        foreach ($this->byFileAndLine($reviews) as $sameLine) {
            $spots = $this->placed($sameLine, $spots);
        }

        return $spots;
    }

    /**
     * @template T of LoopFinding
     *
     * @param list<list<T>> $reviews
     *
     * @return list<non-empty-list<array{int, T}>>
     */
    private function byFileAndLine(array $reviews): array
    {
        $tagged = [];
        foreach ($reviews as $review => $findings) {
            foreach ($findings as $finding) {
                $tagged[] = [$review, $finding];
            }
        }

        $spot = static fn (LoopFinding $finding): array => [$finding->file, $finding->line];
        usort($tagged, static fn (array $a, array $b): int => $spot($a[1]) <=> $spot($b[1]));

        $groups = [];
        $last = -1;
        foreach ($tagged as $entry) {
            if ($last >= 0 && $spot($groups[$last][0][1]) === $spot($entry[1])) {
                $groups[$last][] = $entry;
                continue;
            }

            $groups[] = [$entry];
            ++$last;
        }

        return $groups;
    }

    /**
     * @template T of LoopFinding
     *
     * @param non-empty-list<array{int, T}> $sameLine
     * @param list<MergedSpot<T>>           $spots
     *
     * @return list<MergedSpot<T>>
     */
    private function placed(array $sameLine, array $spots): array
    {
        // Findings on one line tie in the sort, so every one the spot admits joins it before any of them opens a new spot; otherwise the reviewers' order would decide which spots form.
        $last = array_pop($spots);
        $waiting = [];
        foreach ($sameLine as [$review, $finding]) {
            if (null !== $last && $last->admits($finding, $review)) {
                $last = $last->joinedBy($finding, $review);
                continue;
            }

            $waiting[] = [$review, $finding];
        }

        $spots = null === $last ? $spots : [...$spots, $last];
        if ([] === $waiting) {
            return $spots;
        }

        [$review, $finding] = array_shift($waiting);
        $spots[] = MergedSpot::of($finding, $review);

        return [] === $waiting ? $spots : $this->placed($waiting, $spots);
    }

    /**
     * @template L of LoopFinding
     * @template I of LoopFinding
     *
     * @param list<MergedSpot<L>> $loopSpots
     * @param list<MergedSpot<I>> $intaktSpots
     *
     * @return array{list<array{L, I}>, list<L>, list<I>}
     */
    private function match(array $loopSpots, array $intaktSpots): array
    {
        $matches = [];
        $loopOnly = [];
        foreach ($loopSpots as $loopSpot) {
            $index = $this->closest($loopSpot, $intaktSpots);
            if (null === $index) {
                $loopOnly[] = $loopSpot->finding;
                continue;
            }

            $matches[] = [$loopSpot->finding, $intaktSpots[$index]->finding];
            unset($intaktSpots[$index]);
        }

        return [$matches, $loopOnly, array_map(static fn (MergedSpot $spot): LoopFinding => $spot->finding, array_values($intaktSpots))];
    }

    /**
     * @template L of LoopFinding
     * @template I of LoopFinding
     *
     * @param MergedSpot<L>             $loopSpot
     * @param array<int, MergedSpot<I>> $intaktSpots
     */
    private function closest(MergedSpot $loopSpot, array $intaktSpots): ?int
    {
        $closest = null;
        $distance = self::LINE_WINDOW + 1;
        foreach ($intaktSpots as $index => $spot) {
            $apart = $loopSpot->linesApart($spot);
            if ($this->withinWindow($apart) && $apart < $distance) {
                $closest = $index;
                $distance = $apart;
            }
        }

        return $closest;
    }

    private function withinWindow(?int $linesApart): bool
    {
        return null !== $linesApart && $linesApart <= self::LINE_WINDOW;
    }
}
