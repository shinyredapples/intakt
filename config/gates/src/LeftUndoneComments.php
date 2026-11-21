<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class LeftUndoneComments
{
    private const string WHITESPACE = " \t\n\r\v\f";
    private const string MARKER = '/^(TODO|FIXME|XXX)\b/i';
    public const string ISSUE_REFERENCE = '\(#[1-9][0-9]*\):';
    private const string REASONED_EXCEPTION = '/^(?:TODO|FIXME|XXX)'.self::ISSUE_REFERENCE.'.*[[:alpha:]]/i';

    /**
     * @param list<int> $addedLines
     *
     * @return array<int, string>
     */
    public function markersOn(string $source, array $addedLines, CommentLanguage $language): array
    {
        $added = array_flip($addedLines);
        $markers = [];

        foreach ($this->commentLines($source, $language) as [$number, $text]) {
            $marker = isset($added[$number]) ? $this->unreasonedMarkerIn($text, $language) : null;

            if (null !== $marker) {
                $markers[$number] ??= $marker;
            }
        }

        return $markers;
    }

    /**
     * @return list<array{int, string}>
     */
    private function commentLines(string $source, CommentLanguage $language): array
    {
        $lines = [];
        $line = 1;
        $counted = 0;

        foreach ($language->commentsIn($source) as [$position, $comment]) {
            // Because git numbers lines by line feeds only, while PHP also breaks a line at a lone carriage return.
            $line += substr_count($source, "\n", $counted, $position - $counted);
            $counted = $position;

            foreach (explode("\n", $comment) as $offset => $gitLine) {
                foreach (explode("\r", $gitLine) as $text) {
                    $lines[] = [$line + $offset, $text];
                }
            }
        }

        return $lines;
    }

    private function unreasonedMarkerIn(string $commentLine, CommentLanguage $language): ?string
    {
        $text = $this->withoutCommentSyntax($commentLine, $language);

        $marker = $this->matchIn(self::MARKER, $text);

        if (null === $marker || null !== $this->matchIn(self::REASONED_EXCEPTION, $text)) {
            return null;
        }

        return $marker[1] ?? null;
    }

    // Because a repeated group exhausts the pattern engine's stack on thousands of nested markers, one marker is stripped per match.
    private function withoutCommentSyntax(string $commentLine, CommentLanguage $language): string
    {
        $offset = strspn($commentLine, self::WHITESPACE);

        while (null !== $syntax = $this->matchIn($language->lineSyntax(), $commentLine, $offset)) {
            $offset += strlen($syntax[0] ?? '');
        }

        return substr($commentLine, $offset);
    }

    /**
     * @return array<int|string, string>|null
     */
    private function matchIn(string $pattern, string $subject, int $offset = 0): ?array
    {
        $result = preg_match($pattern, $subject, $match, 0, $offset);

        if (false === $result) {
            throw new ScopeFailure('cannot read a comment: '.preg_last_error_msg());
        }

        return 1 === $result ? $match : null;
    }
}
