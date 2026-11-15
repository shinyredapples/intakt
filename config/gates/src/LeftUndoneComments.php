<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class LeftUndoneComments
{
    private const string COMMENT_SYNTAX = '~^\s*(?://+|#+|/\*+|\*+(?!/))?\s*~';
    private const string MARKER = '/^(TODO|FIXME|XXX)\b/i';
    public const string ISSUE_REFERENCE = '\(#[1-9][0-9]*\):';
    private const string REASONED_EXCEPTION = '/^(?:TODO|FIXME|XXX)'.self::ISSUE_REFERENCE.'.*[[:alpha:]]/i';
    private const string LINE_BREAK = '/\r\n|\r|\n/';

    /**
     * @param list<int> $addedLines
     *
     * @return array<int, string>
     */
    public function markersOn(string $source, array $addedLines): array
    {
        $added = array_flip($addedLines);
        $markers = [];

        foreach ($this->commentLines($source) as [$number, $text]) {
            $marker = isset($added[$number]) ? $this->unreasonedMarkerIn($text) : null;

            if (null !== $marker) {
                $markers[$number] ??= $marker;
            }
        }

        return $markers;
    }

    /**
     * @return list<array{int, string}>
     */
    private function commentLines(string $source): array
    {
        $lines = [];
        $line = 1;
        $counted = 0;

        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            // Because git numbers lines by line feeds only, while PHP also breaks a line at a lone carriage return.
            $line += substr_count($source, "\n", $counted, $token->pos - $counted);
            $counted = $token->pos;

            foreach (preg_split(self::LINE_BREAK, $token->text, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$text, $offset]) {
                $lines[] = [$line + substr_count($token->text, "\n", 0, $offset), $text];
            }
        }

        return $lines;
    }

    private function unreasonedMarkerIn(string $commentLine): ?string
    {
        $text = (string) preg_replace(self::COMMENT_SYNTAX, '', $commentLine);

        if (1 !== preg_match(self::MARKER, $text, $marker) || 1 === preg_match(self::REASONED_EXCEPTION, $text)) {
            return null;
        }

        return $marker[1];
    }
}
