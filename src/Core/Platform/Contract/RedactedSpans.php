<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use Closure;
use Generator;

// The parts of one text to redact, each by its start and end in the original text.
final class RedactedSpans
{
    // A short held credential may match at every byte of a long text, so a part is one integer (start high, end inverted low, which also sorts it by start and then longest first) instead of a small array, which would cost about ten times the memory.
    private const int END_BITS = 32;

    private const int END_MASK = (1 << self::END_BITS) - 1;

    /** @var list<int> */
    private array $keys = [];

    /** @var array<int, string> only the replacements other than the marker, by key */
    private array $replacements = [];

    public function add(int $start, int $end, string $replacement = Redaction::MARKER): void
    {
        $key = ($start << self::END_BITS) | (self::END_MASK - $end);
        $this->keys[] = $key;
        if (Redaction::MARKER !== $replacement) {
            $this->replacements[$key] = $this->longer($this->replacements[$key] ?? Redaction::MARKER, $replacement);
        }
    }

    public function addAll(self $other): void
    {
        foreach ($other->keys as $key) {
            $this->keys[] = $key;
        }
        foreach ($other->replacements as $key => $replacement) {
            $this->replacements[$key] = $this->longer($this->replacements[$key] ?? Redaction::MARKER, $replacement);
        }
    }

    // Each part is masked byte for byte with bytes no pattern takes as a header, scheme, quote or word, so a later pass sees every other byte where it was.
    public function maskedIn(string $text): string
    {
        return $this->rewritten($text, static fn (int $start, int $end): string => str_repeat(Redaction::MASK, $end - $start));
    }

    // A line break inside a part still ends the line it ends in the original text, so a header value after it does not run on past it.
    public function maskedKeepingLineBreaksIn(string $text): string
    {
        return $this->rewritten($text, static fn (int $start, int $end): string => (string) preg_replace('~[^\r\n]~', Redaction::MASK, substr($text, $start, $end - $start)));
    }

    public function replacedIn(string $text): string
    {
        return $this->rewritten($text, static fn (int $start, int $end, string $replacement): string => $replacement);
    }

    /**
     * @param Closure(int, int, string): string $replacement
     */
    private function rewritten(string $text, Closure $replacement): string
    {
        $result = '';
        $offset = 0;
        foreach ($this->merged() as [$start, $end, $with]) {
            $result .= substr($text, $offset, $start - $offset).$replacement($start, $end, $with);
            $offset = $end;
        }

        return $result.substr($text, $offset);
    }

    // Overlapping parts, and a credential found in full and trimmed at one place, merge into one marker; parts that only touch keep one each.
    /**
     * @return Generator<int, array{int, int, string}>
     */
    private function merged(): Generator
    {
        $keys = $this->keys;
        sort($keys);

        $current = null;
        foreach ($keys as $key) {
            $next = [$key >> self::END_BITS, self::END_MASK - ($key & self::END_MASK), $this->replacements[$key] ?? Redaction::MARKER];
            if (null !== $current && ($next[0] < $current[1] || $next[0] === $current[0])) {
                $current = $this->joined($current, $next);

                continue;
            }
            if (null !== $current) {
                yield $current;
            }
            $current = $next;
        }
        if (null !== $current) {
            yield $current;
        }
    }

    // The replacement of the part that reaches furthest is kept, because only a part that ends the text adds a closing quote after its marker.
    /**
     * @param array{int, int, string} $current
     * @param array{int, int, string} $next
     *
     * @return array{int, int, string}
     */
    private function joined(array $current, array $next): array
    {
        return match ($next[1] <=> $current[1]) {
            1 => [$current[0], $next[1], $next[2]],
            0 => [$current[0], $current[1], $this->longer($current[2], $next[2])],
            -1 => $current,
        };
    }

    private function longer(string $replacement, string $other): string
    {
        return strlen($other) > strlen($replacement) ? $other : $replacement;
    }
}
