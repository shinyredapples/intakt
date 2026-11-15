<?php

declare(strict_types=1);

namespace Intakt\Gates;

use PhpToken;

final readonly class LeftUndoneComments
{
    private const string COMMENT_SYNTAX = '~^\s*(?://+|#+|/\*+|\*+(?!/))?\s*~';
    private const string MARKER = '/^(TODO|FIXME|XXX)\b/i';
    private const string REASONED_EXCEPTION = '/^(?:TODO|FIXME|XXX)\(#[1-9][0-9]*\):.*[[:alpha:]]/i';

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
                $markers[$number] = $marker;
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

        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            foreach (explode("\n", $token->text) as $offset => $text) {
                $lines[] = [$token->line + $offset, $text];
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
