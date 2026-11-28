<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Every run is found once and every closer is passed once, because the text is model output of any length and a rescan per opener would take quadratic time.
#[Exclude]
final class BacktickRuns
{
    /** @var array<int, list<int>> */
    private array $offsetsByLength = [];

    /** @var array<int, int> */
    private array $nextByLength = [];

    public function __construct(string $text)
    {
        preg_match_all('/`+/', $text, $runs, PREG_OFFSET_CAPTURE);
        foreach ($runs[0] as [$run, $offset]) {
            $this->offsetsByLength[strlen($run)][] = $offset;
        }
    }

    // Asked with a growing $from only, so the pointer of each length moves forward and never back.
    public function spanEnd(int $length, int $from): int
    {
        $offsets = $this->offsetsByLength[$length] ?? [];
        $next = $this->nextByLength[$length] ?? 0;
        while (isset($offsets[$next]) && $offsets[$next] < $from) {
            ++$next;
        }
        $this->nextByLength[$length] = $next;

        return isset($offsets[$next]) ? $offsets[$next] + $length : $from;
    }
}
