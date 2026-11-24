<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// A match consumes its text, so a token that starts inside it (`sk-...` or a web token's signature running on into the next token) would be missed. The scan therefore resumes at the last start inside the match: that match extends the part when it ends further on, and otherwise the scan goes on after it. Starts are looked for only past the text already searched, so each character is read a bounded number of times and the time grows linearly.
final readonly class SecretShape
{
    private string $startPattern;

    private string $shapePattern;

    public function __construct(string $start, string $rest)
    {
        $this->startPattern = '~'.$start.'~';
        $this->shapePattern = '~(?:'.$start.$rest.')()~s';
    }

    public function addFoundIn(string $text, RedactedSpans $spans): bool
    {
        $offset = 0;
        $searched = 0;
        while (true) {
            $found = preg_match($this->shapePattern, $text, $match, PREG_OFFSET_CAPTURE, $offset);
            if (1 !== $found) {
                return 0 === $found;
            }
            $from = $match[0][1];
            $to = $match[1][1];
            $spans->add($from, $to);

            $lastStart = $this->lastStartBetween($text, max($from + 1, $searched), $to);
            if (false === $lastStart) {
                return false;
            }
            $searched = max($searched, $to);
            $offset = $lastStart ?? max($to, $from + 1);
        }
    }

    private function lastStartBetween(string $text, int $from, int $to): int|false|null
    {
        $last = null;
        while ($from < $to) {
            $found = preg_match($this->startPattern, $text, $start, PREG_OFFSET_CAPTURE, $from);
            if (false === $found) {
                return false;
            }
            if (0 === $found || $start[0][1] >= $to) {
                break;
            }
            $last = $start[0][1];
            $from = $last + 1;
        }

        return $last;
    }
}
