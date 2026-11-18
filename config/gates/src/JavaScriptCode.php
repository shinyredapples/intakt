<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;

// Because the scanner hands over code between strings, comments and brackets, only words and punctuators are left to tell apart here.
final readonly class JavaScriptCode
{
    private const string ASCII_SPACE = " \t\n\r\v\f";
    // Because a private name such as `#count` is read like a word, the hash counts as part of one.
    private const string WORD_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$#';
    // Because JavaScript also separates tokens with these, a keyword before one of them is still the keyword.
    private const array UNICODE_SPACES = ["\u{00A0}", "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}", "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}", "\u{FEFF}"];
    private const array LONG_PUNCTUATORS = ['...', '=>', '++', '--', '??', '?.'];

    /**
     * @return Generator<int, string>
     */
    public function tokensIn(string $code): Generator
    {
        $length = strlen($code);
        $at = strspn($code, self::ASCII_SPACE);

        while ($at < $length) {
            $token = $this->tokenAt($code, $at);

            if ('' !== $token) {
                yield $token;
            }

            $at += max(strlen($token), $this->unicodeSpaceAt($code, $at));
            $at += strspn($code, self::ASCII_SPACE, $at);
        }
    }

    private function tokenAt(string $code, int $at): string
    {
        $end = $this->wordEnd($code, $at);

        if ($end === $at && ord($code[$at]) < 0x80) {
            return $this->punctuatorAt($code, $at);
        }

        return substr($code, $at, $end - $at);
    }

    // Because identifiers may hold any Unicode letter, every byte of a multibyte character counts as part of a word unless it starts a Unicode space.
    private function wordEnd(string $code, int $at): int
    {
        $length = strlen($code);

        while (($at += strspn($code, self::WORD_CHARACTERS, $at)) < $length && ord($code[$at]) >= 0x80 && 0 === $this->unicodeSpaceAt($code, $at)) {
            ++$at;
        }

        return $at;
    }

    // Because `?.` before a digit is a conditional followed by a number, as in `a?.5:b`, it is no optional chaining there.
    private function punctuatorAt(string $code, int $at): string
    {
        foreach (self::LONG_PUNCTUATORS as $punctuator) {
            $digitFollows = ctype_digit($code[$at + 2] ?? '');

            if (0 === substr_compare($code, $punctuator, $at, strlen($punctuator)) && ('?.' !== $punctuator || !$digitFollows)) {
                return $punctuator;
            }
        }

        return $code[$at];
    }

    private function unicodeSpaceAt(string $code, int $at): int
    {
        foreach (self::UNICODE_SPACES as $space) {
            if (0 === substr_compare($code, $space, $at, strlen($space))) {
                return strlen($space);
            }
        }

        return 0;
    }
}
