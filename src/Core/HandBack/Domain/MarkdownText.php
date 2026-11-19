<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use RuntimeException;

// Both the task and the pull request text are judged by what the forge renders, so text a reader never sees (an HTML comment) must not count, while a fenced block is shown but is code, not structure.
final readonly class MarkdownText
{
    // One pass from left to right, because whichever of a fence and a comment opens first hides the other's markers (https://spec.commonmark.org/0.31.2/#fenced-code-blocks); a fence closes only with a run of its own character at least as long.
    private const string HIDDEN_OR_FENCED = '/(?<fence>^ {0,3}(?:(?<tick>`{3,})[^`\n]*|(?<tilde>~{3,})[^\n]*)(?:\n[\s\S]*?)??(?:\n {0,3}(?:\k<tick>`*|\k<tilde>~*)[ \t]*(?=\n|\z)|\z))|(?<comment><!--[\s\S]*?(?:-->|\z))/m';

    public static function visible(string $markdown): string
    {
        return self::replaced($markdown, static fn (array $match): string => '' !== $match['fence'] ? $match[0] : self::lineBreaksOf($match[0]));
    }

    public static function visibleProse(string $markdown): string
    {
        return self::replaced($markdown, static fn (array $match): string => self::lineBreaksOf($match[0]));
    }

    /**
     * @param callable(array<array-key, string>): string $replacement
     */
    private static function replaced(string $markdown, callable $replacement): string
    {
        // An unreadable text must stop the check, because reading it as empty would pass a task's Evidence list unseen.
        return preg_replace_callback(self::HIDDEN_OR_FENCED, $replacement, str_replace(["\r\n", "\r"], "\n", $markdown))
            ?? throw new RuntimeException('the Markdown text cannot be read: '.preg_last_error_msg());
    }

    // Hidden and fenced text keeps its line breaks, because the lines around it keep their place.
    private static function lineBreaksOf(string $text): string
    {
        return str_repeat("\n", substr_count($text, "\n"));
    }
}
