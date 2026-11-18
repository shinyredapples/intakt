<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use NoDiscard;
use SensitiveParameter;

// One home for what must never leave Intakt in text: posted review text and log records both pass through it.
/**
 * @phpstan-type Span array{int, int, string} start, end and replacement of a part to redact, in the original text
 */
final readonly class Redaction
{
    public const string MARKER = '[redacted]';

    // A found part is masked with bytes no pattern takes as a header, scheme, quote or word, so the next pass sees every other byte where it was.
    public const string MASK = "\0";

    private const string SECRET_SHAPES = '~'
        .'-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)'
        .'|\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})'
        .'|\bsk-[A-Za-z0-9_-]{20,}'
        .'|\bAKIA[0-9A-Z]{16}\b'
        .'|\bxox[abprs]-[A-Za-z0-9-]{10,}'
        .'|\beyJ[\w-]{8,}\.eyJ[\w-]{8,}\.[\w-]+'
        .'|\bBearer\s+[A-Za-z0-9._\\~+/=-]{8,}'
        .'~s';

    public function __construct(
        private HeldCredentialSpans $heldCredentials = new HeldCredentialSpans(),
        private HeaderSpans $headers = new HeaderSpans(),
    ) {
    }

    // Every pass finds its parts in the original text and all parts are replaced at the end, so a held credential that is also a header name or a scheme still lets its header or URL be found, and overlapping parts become one marker.
    #[NoDiscard]
    public function redact(string $text, #[SensitiveParameter] string ...$heldCredentials): string
    {
        $held = $this->heldCredentials->in($text, array_values($heldCredentials));
        [$lists, $keptTails] = $this->headers->lists($text);
        $masked = $this->masked($text, $lists);
        $values = $this->headers->values($masked, $keptTails);
        // A failed match would leave the rest unredacted, so nothing of the text goes out.
        if (null === $held || null === $values) {
            return self::MARKER;
        }

        $masked = $this->masked($masked, $values);
        $userinfo = $this->userinfoSpans($masked);
        if (null === $userinfo) {
            return self::MARKER;
        }

        $shapes = $this->secretShapeSpans($this->masked($masked, $userinfo));
        if (null === $shapes) {
            return self::MARKER;
        }

        return $this->replaced($text, [...$held, ...$lists, ...$values, ...$userinfo, ...$shapes]);
    }

    /**
     * @return list<Span>|null
     */
    private function userinfoSpans(string $text): ?array
    {
        // Backslashes are allowed before each slash because JSON writes the separator as :\/\/, once more per encoding.
        if (false === preg_match_all('~(?<scheme>\b[a-z][a-z0-9+.-]*:(?:\\\\*/){2})[^\s/?#"\'<>]+@~i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return array_map(
            static fn (array $scheme, array $whole): array => [$scheme[1] + strlen($scheme[0]), $whole[1] + strlen($whole[0]) - 1, self::MARKER],
            $matches['scheme'],
            $matches[0],
        );
    }

    /**
     * @return list<Span>|null
     */
    private function secretShapeSpans(string $text): ?array
    {
        if (false === preg_match_all(self::SECRET_SHAPES, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return array_map(static fn (array $shape): array => [$shape[1], $shape[1] + strlen($shape[0]), self::MARKER], $matches[0]);
    }

    /**
     * @param list<Span> $spans
     */
    private function masked(string $text, array $spans): string
    {
        foreach ($spans as [$start, $end]) {
            $text = substr_replace($text, str_repeat(self::MASK, $end - $start), $start, $end - $start);
        }

        return $text;
    }

    /**
     * @param list<Span> $spans
     */
    private function replaced(string $text, array $spans): string
    {
        $result = '';
        $offset = 0;
        foreach ($this->merged($spans) as [$start, $end, $replacement]) {
            $result .= substr($text, $offset, $start - $offset).$replacement;
            $offset = $end;
        }

        return $result.substr($text, $offset);
    }

    // Overlapping parts, and a credential found in full and trimmed at one place, merge into one marker; parts that only touch keep one each.
    /**
     * @param list<Span> $spans
     *
     * @return list<Span>
     */
    private function merged(array $spans): array
    {
        usort($spans, static fn (array $a, array $b): int => [$a[0], $b[1]] <=> [$b[0], $a[1]]);

        $merged = [];
        foreach ($spans as $span) {
            $last = array_key_last($merged);
            if (null === $last || !$this->overlaps($merged[$last], $span)) {
                $merged[] = $span;

                continue;
            }

            $merged[$last] = $span[1] > $merged[$last][1] ? [$merged[$last][0], $span[1], $span[2]] : $merged[$last];
        }

        return $merged;
    }

    /**
     * @param Span $kept
     * @param Span $next
     */
    private function overlaps(array $kept, array $next): bool
    {
        return $next[0] < $kept[1] || $next[0] === $kept[0];
    }
}
