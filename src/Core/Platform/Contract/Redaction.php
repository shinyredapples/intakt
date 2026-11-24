<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use NoDiscard;
use SensitiveParameter;

// One home for what must never leave Intakt in text: posted review text and log records both pass through it.
final readonly class Redaction
{
    public const string MARKER = '[redacted]';

    public const string MASK = "\0";

    // A prefixed shape starts after any character but a letter or digit, since `\b` would miss a token right after `_` (`key_ghp_...`). Each shape is tried at every position inside a lookahead, so a match does not consume its text: `sk-...` or a web token's signature running on into `-eyJ...` would otherwise hide the token that starts inside it.
    private const array SECRET_SHAPES = [
        '-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|\z)',
        '(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})',
        '(?<![A-Za-z0-9])sk-[A-Za-z0-9_-]{20,}',
        '(?<![A-Za-z0-9])AKIA[0-9A-Z]{16}\b',
        '(?<![A-Za-z0-9])xox[abprs]-[A-Za-z0-9-]{10,}',
        '(?<![A-Za-z0-9])eyJ[\w-]{8,}\.eyJ[\w-]{8,}\.[\w-]+',
        '\bBearer\s+[A-Za-z0-9._\\~+/=-]{8,}',
    ];

    // Headers, URLs and secret shapes are looked for both in the original text and with the held credentials masked: a held credential may spell a header name or a scheme, or end right before one and hide the word boundary its pattern needs. All parts are replaced at the end, so overlapping parts become one marker.
    #[NoDiscard]
    public function redact(string $text, #[SensitiveParameter] string ...$heldCredentials): string
    {
        $spans = new HeldCredentialSpans()->in($text, ...$heldCredentials);
        // A failed match would leave the rest unredacted, so nothing of the text goes out.
        if (!$spans instanceof RedactedSpans) {
            return self::MARKER;
        }

        foreach (array_unique([$text, $spans->maskedKeepingLineBreaksIn($text)]) as $view) {
            $found = $this->structuredSpans($view);
            if (!$found instanceof RedactedSpans) {
                return self::MARKER;
            }
            $spans->addAll($found);
        }

        return $spans->replacedIn($text);
    }

    // Each pass looks at the text with the parts of the earlier passes masked, so a header value is not taken again as a URL or a secret shape.
    private function structuredSpans(string $text): ?RedactedSpans
    {
        $headers = new HeaderSpans();
        $listed = $headers->lists($text);
        $spans = $listed->spans;
        $values = $headers->values($spans->maskedIn($text), $listed);
        if (!$values instanceof RedactedSpans) {
            return null;
        }

        $spans->addAll($values);
        if (!$this->addUserinfo($spans->maskedIn($text), $spans)) {
            return null;
        }

        return $this->addSecretShapes($spans->maskedIn($text), $spans) ? $spans : null;
    }

    private function addUserinfo(string $text, RedactedSpans $spans): bool
    {
        // Backslashes are allowed before each slash because JSON writes the separator as :\/\/, once more per encoding.
        if (false === preg_match_all('~(?<scheme>\b[a-z][a-z0-9+.-]*:(?:\\\\*/){2})[^\s/?#"\'<>]+@~i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as $i => [$whole, $at]) {
            $scheme = $matches['scheme'][$i];
            $spans->add($scheme[1] + strlen($scheme[0]), $at + strlen($whole) - 1);
        }

        return true;
    }

    private function addSecretShapes(string $text, RedactedSpans $spans): bool
    {
        foreach (self::SECRET_SHAPES as $shapePattern) {
            if (false === preg_match_all('~(?=('.$shapePattern.'))~s', $text, $matches, PREG_OFFSET_CAPTURE)) {
                return false;
            }

            foreach ($matches[1] as [$shape, $at]) {
                $spans->add($at, $at + strlen($shape));
            }
        }

        return true;
    }
}
