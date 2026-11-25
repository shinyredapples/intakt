<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Contract\QuotesCounted;

final readonly class RoundFinding
{
    public JudgeCall $call;

    /**
     * @param list<string>         $merged
     * @param list<QuoteNearLines> $nearest
     */
    public function __construct(
        public string $id,
        public ?string $severity,
        public ?string $text,
        public array $merged = [],
        public ?string $file = null,
        public ?int $line = null,
        public ?string $droppedBecause = null,
        public QuotesCounted $quotes = new QuotesCounted(),
        private array $nearest = [],
    ) {
        $this->call = JudgeCall::NotActedOn;
    }

    /**
     * @return list<NearLine>
     */
    public function nearLinesOf(string $quote): array
    {
        // Looked up by the quote the ledger row recorded, because the page never gets the change text and must not search it again.
        return array_find($this->nearest, static fn (QuoteNearLines $near): bool => $near->quote === $quote)->lines ?? [];
    }

    /**
     * @return non-empty-list<string>
     */
    public function ids(): array
    {
        return [$this->id, ...$this->merged];
    }

    // The first sentence of its first line, because a finding has no title of its own and a card shows one short line; null when no text is recorded, Unicode white space included.
    public function headline(): ?string
    {
        $text = $this->trimmedText();
        if (null === $text) {
            return null;
        }

        // Code spans are found on the whole text before any cut, so a line break or a mark inside one never ends the headline.
        $at = 0;
        foreach ([...$this->codeSpansOf($text), [strlen($text), '', '']] as [$start, $span]) {
            // A sentence ends at a mark followed by white space or the end, so a dot in a name (src/A.php, 1.5) never ends it. Searched gap by gap instead of in one pattern over the line, which runs out of PCRE's stack on a long first sentence.
            if (1 === preg_match('/\R|[.!?](?=[\s\p{Z}]|$)/u', $text, $end, PREG_OFFSET_CAPTURE, $at) && $end[0][1] < $start) {
                $cut = $end[0][1] + (str_contains('.!?', $end[0][0]) ? 1 : 0);

                return preg_replace('/[\s\p{Z}]+$/u', '', substr($text, 0, $cut));
            }

            $at = $start + strlen($span);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    public function quotedCode(): array
    {
        // The reviewer's own code spans, found as the headline finds them, and not the quote check's counted list, which leaves out a quote it cannot check (a lone `0`) that the reviewer still gave.
        return array_column($this->codeSpansOf($this->text ?? ''), 2);
    }

    public function goesOnAfterItsHeadline(): bool
    {
        $headline = $this->headline();

        return null !== $headline && $headline !== $this->trimmedText();
    }

    public function urgency(): ?FindingUrgency
    {
        return FindingUrgency::of($this->severity);
    }

    public function place(): ?string
    {
        if (null === $this->file) {
            return null;
        }

        return null === $this->line ? $this->file : $this->file.':'.$this->line;
    }

    public function callInWords(): string
    {
        return $this->call->inWords();
    }

    public function calledBy(JudgeCall $call): self
    {
        return clone ($this, ['call' => $call]);
    }

    /**
     * Spans as FindingText finds them: a run of backticks closed by the next run of the same length, across lines too; an unclosed run is plain text. Scanned with string functions instead of a lazy pattern, which runs out of PCRE's backtracking limit on a long span and then finds no span at all.
     *
     * @return list<array{int, string, string}> the offset, the whole span and the code inside it
     */
    private function codeSpansOf(string $text): array
    {
        $spans = [];
        $at = 0;
        while (false !== $open = strpos($text, '`', $at)) {
            $fence = strspn($text, '`', $open);
            $close = $this->closingRunOf($text, $fence, $open + $fence);
            if (null === $close) {
                $at = $open + $fence;

                continue;
            }

            $spans[] = [$open, substr($text, $open, $close + $fence - $open), substr($text, $open + $fence, $close - $open - $fence)];
            $at = $close + $fence;
        }

        return $spans;
    }

    private function closingRunOf(string $text, int $fence, int $from): ?int
    {
        while (false !== $close = strpos($text, '`', $from)) {
            $run = strspn($text, '`', $close);
            if ($run === $fence) {
                return $close;
            }

            $from = $close + $run;
        }

        return null;
    }

    // Null for a text that is missing, blank or not valid UTF-8, because the Unicode-aware pattern fails on invalid UTF-8 and the card then says the text is not recorded.
    private function trimmedText(): ?string
    {
        $trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', (string) $this->text);

        return '' === $trimmed ? null : $trimmed;
    }
}
