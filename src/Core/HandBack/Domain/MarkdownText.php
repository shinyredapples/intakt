<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// Both the task and the pull request text are judged by what the forge renders, so text a reader never sees (an HTML comment) must not count, while a fenced block is shown but is code, not structure.
final readonly class MarkdownText
{
    private const string FENCE_OPEN = '/^ {0,3}(`{3,}(?=[^`]*$)|~{3,})/';

    private const string COMMENT_OPEN = '<!--';

    private const string COMMENT_CLOSE = '-->';

    /**
     * @return list<array{string, bool}> each line without its hidden parts, and whether it belongs to a fenced block
     */
    public static function visibleLines(string $markdown): array
    {
        $lines = [];
        $fence = null;
        $inComment = false;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            if (null !== $fence) {
                $fence = self::closes($fence, $line) ? null : $fence;
                $lines[] = [$line, true];
            } elseif (!$inComment && 1 === preg_match(self::FENCE_OPEN, $line, $match)) {
                $fence = $match[1];
                $lines[] = [$line, true];
            } else {
                [$visible, $inComment] = self::withoutComments($line, $inComment);
                $lines[] = [$visible, false];
            }
        }

        return $lines;
    }

    public static function visible(string $markdown): string
    {
        return implode("\n", array_map(static fn (array $line): string => $line[0], self::visibleLines($markdown)));
    }

    // CommonMark closes a fence only with a run of the same character, at least as long, and nothing after it.
    private static function closes(string $fence, string $line): bool
    {
        return 1 === preg_match('/^ {0,3}'.preg_quote($fence[0], '/').'{'.strlen($fence).',}[ \t]*$/', $line);
    }

    /**
     * @return array{string, bool}
     */
    private static function withoutComments(string $line, bool $inComment): array
    {
        $visible = '';
        $rest = $line;

        while (true) {
            if ($inComment) {
                $end = strpos($rest, self::COMMENT_CLOSE);

                if (false === $end) {
                    return [$visible, true];
                }

                $rest = substr($rest, $end + strlen(self::COMMENT_CLOSE));
            }

            $start = strpos($rest, self::COMMENT_OPEN);

            if (false === $start) {
                return [$visible.$rest, false];
            }

            $visible .= substr($rest, 0, $start);
            $rest = substr($rest, $start + strlen(self::COMMENT_OPEN));
            $inComment = true;
        }
    }
}
