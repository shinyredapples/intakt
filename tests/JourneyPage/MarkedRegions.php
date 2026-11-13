<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// Only the lines between the markers change, so that the hand-written text around them stays byte for byte.
final readonly class MarkedRegions
{
    /**
     * @param list<PageRegion> $regions
     */
    public function replaced(string $page, array $regions): string
    {
        return array_reduce($regions, $this->withRegion(...), $page);
    }

    private function withRegion(string $page, PageRegion $region): string
    {
        $begin = '<!-- journey:begin '.$region->name.' -->'."\n";
        $end = '<!-- journey:end '.$region->name.' -->';

        if (1 !== substr_count($page, $begin) || 1 !== substr_count($page, $end)) {
            throw new JourneyInputFailed('the page needs exactly one begin and one end marker for '.$region->name);
        }

        $start = (int) strpos($page, $begin) + strlen($begin);
        $finish = (int) strpos($page, $end);

        if ($finish < $start) {
            throw new JourneyInputFailed('the end marker for '.$region->name.' comes before its begin marker');
        }

        return substr($page, 0, $start).rtrim($region->text, "\n")."\n".substr($page, $finish);
    }
}
