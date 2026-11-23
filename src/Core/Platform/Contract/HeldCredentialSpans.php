<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use SensitiveParameter;

final readonly class HeldCredentialSpans
{
    private const array JSON_SHORT_ESCAPES = ["\x08" => 'b', "\f" => 'f', "\n" => 'n', "\r" => 'r', "\t" => 't'];

    public function in(string $text, #[SensitiveParameter] string ...$credentials): ?RedactedSpans
    {
        $spans = new RedactedSpans();
        foreach ($this->withTrimmedForms(array_values($credentials)) as $credential) {
            $pattern = $this->pattern($credential);
            // One match at a time, because collecting every match of a short credential in a long text first would cost far more memory than the parts.
            $offset = 0;
            while (1 === preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $offset = $match[0][1] + strlen($match[0][0]);
                $spans->add($match[0][1], $offset);
            }
            if (PREG_NO_ERROR !== preg_last_error()) {
                return null;
            }
        }

        return $spans;
    }

    // A value is often stored with a trailing newline (a secrets file, a heredoc) but quoted without it, so its trimmed form is held too.
    /**
     * @param list<string> $credentials
     *
     * @return list<string>
     */
    private function withTrimmedForms(array $credentials): array
    {
        $forms = array_filter([...$credentials, ...array_map(trim(...), $credentials)], static fn (string $form): bool => '' !== $form);

        return array_values(array_unique($forms));
    }

    private function pattern(string $credential): string
    {
        $chars = mb_str_split($credential, 1, 'UTF-8');

        return '~'.implode('', array_map($this->jsonSpellings(...), $chars)).'~';
    }

    // Every spelling is allowed because JSON may write a character as itself, a short escape or a \u escape, with more backslashes per encoding.
    private function jsonSpellings(string $char): string
    {
        // A plain ASCII character matches only as itself, so a long credential's pattern stays well under PCRE's compiled size.
        if (!$this->jsonMayEscape($char)) {
            return preg_quote($char, '~');
        }

        $spellings = [in_array($char, ['"', '\\', '/'], true) ? '\\\\*'.preg_quote($char, '~') : preg_quote($char, '~')];
        if (isset(self::JSON_SHORT_ESCAPES[$char])) {
            $spellings[] = '\\\\+'.self::JSON_SHORT_ESCAPES[$char];
        }
        if (mb_check_encoding($char, 'UTF-8')) {
            $spellings[] = $this->unicodeEscape($char);
        }

        return '(?:'.implode('|', $spellings).')';
    }

    // Only control characters, non-ASCII and the JSON_HEX_* characters get escape spellings, because json_encode escapes nothing else and each spelling grows the pattern.
    private function jsonMayEscape(string $char): bool
    {
        return strlen($char) > 1 || ord($char[0]) < 0x20 || str_contains('"\\/<>&\'', $char);
    }

    private function unicodeEscape(string $char): string
    {
        // One escape per UTF-16 unit, because JSON writes a character outside the basic plane as a surrogate pair.
        $units = str_split(bin2hex(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8')), 4);

        return implode('', array_map(static fn (string $unit): string => '\\\\+u(?i:'.$unit.')', $units));
    }
}
