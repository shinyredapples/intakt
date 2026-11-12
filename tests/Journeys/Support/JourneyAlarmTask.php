<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Uri\Rfc3986\Uri;

final readonly class JourneyAlarmTask
{
    public const string MARKER = '<!-- intakt:journey-alarm -->';
    public const array LABELS = ['priority:urgent', 'ready'];
    public const int TAIL_LINES = 40;
    // The body stays below this because GitHub refuses an issue body of more than 65536 characters.
    public const int BODY_LIMIT = 65536;
    private const int NAME_WIDTH = 60;

    public function __construct(
        private JourneyRun $run,
        private ?Uri $link,
    ) {
    }

    public function title(): string
    {
        if ($this->run->timedOut) {
            return 'fix(journeys): the journeys run timed out on main';
        }

        $journeys = array_unique(array_map(static fn (JourneyFailure $failure): string => $failure->journey, $this->run->failures));

        if (1 === count($journeys)) {
            return 'fix(journeys): journey "'.mb_strimwidth(array_first($journeys), 0, self::NAME_WIDTH, '...').'" fails on main';
        }

        return 'fix(journeys): '.count($journeys).' journeys fail on main';
    }

    public function body(): string
    {
        $head = $this->head();
        $budget = self::BODY_LIMIT - 1 - mb_strlen($head);

        if ($budget <= 0) {
            return mb_substr($head, 0, self::BODY_LIMIT - 1);
        }

        return $head.$this->tailBlock($budget);
    }

    private function head(): string
    {
        $failures = array_map(
            static fn (JourneyFailure $failure): string => '- `'.$failure->file.'`: journey "'.$failure->journey.'" failed at step "'.$failure->step.'": '.$failure->reason,
            $this->run->failures,
        );
        $link = $this->link instanceof Uri ? "\nRun: ".$this->link->toString()."\n" : '';

        $what = $this->run->timedOut ? 'The journeys run on `main` timed out, so its output may be cut off.' : 'The journeys failed on `main`.';

        $list = [] === $failures ? '' : "\n".implode("\n", $failures)."\n";

        return self::MARKER."\n".$what."\n".$list.$link
            ."\nLast ".self::TAIL_LINES." lines of the output:\n\n";
    }

    private function tailBlock(int $budget): string
    {
        $tail = $this->run->tail(self::TAIL_LINES);
        $fence = str_repeat('`', max(3, $this->longestBacktickRun($tail) + 1));
        $room = $budget - 2 * mb_strlen($fence) - 3;

        if ($room <= 0) {
            return '';
        }

        // The end of the output is where the failure shows, so a cut drops the start of the tail.
        return $fence."\n".mb_substr($tail, -$room)."\n".$fence."\n";
    }

    private function longestBacktickRun(string $text): int
    {
        preg_match_all('/`+/', $text, $runs);

        return array_reduce($runs[0], static fn (int $longest, string $run): int => max($longest, strlen($run)), 0);
    }
}
