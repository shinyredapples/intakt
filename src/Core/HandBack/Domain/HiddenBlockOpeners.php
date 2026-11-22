<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// A hidden comment or fence leaves blank lines in the task's prose, but its opening line still ends a list item it is indented less than, as Markdown reads it (https://spec.commonmark.org/0.31.2/#list-items). A thematic break at the same indent ends the item the same way and is no item, heading or reference, so it stands in for the hidden opening line.
final readonly class HiddenBlockOpeners
{
    private const string BLOCK_OPENER = '/^( {0,3})(?:<!--|`{3,}|~{3,})/';

    private const string EMPTY_MARKER = '/^ *(?:[-*+]|\d{1,9}[.)]) *$/';

    private const string MARKER_WITH_CONTENT = '/^ *(?:[-*+]|\d{1,9}[.)]) +\S/';

    /**
     * @return list<string>
     */
    public static function proseLinesOf(string $task): array
    {
        // Split like the prose reader, which reads a CR or CRLF as a line break too, so the raw and the visible lines stay aligned.
        $task = str_replace(["\r\n", "\r"], "\n", $task);
        // A byte order mark is no text, so a task that starts with one still starts with its heading.
        $task = str_starts_with($task, "\u{FEFF}") ? substr($task, 3) : $task;
        $raw = array_map(MarkdownText::withSpacesForTabs(...), explode("\n", $task));
        $visible = array_map(MarkdownText::withSpacesForTabs(...), explode("\n", MarkdownText::visibleProse($task)));

        foreach ($visible as $index => $line) {
            if ('' === trim($line) && 1 === preg_match(self::BLOCK_OPENER, $raw[$index] ?? '', $match)) {
                $visible[$index] = $match[1].'***';
            } elseif (1 === preg_match(self::EMPTY_MARKER, $line) && 1 === preg_match(self::MARKER_WITH_CONTENT, $raw[$index] ?? '')) {
                // A comment or fence on the marker line is the item's content, so the item is not empty and interrupts a paragraph like any other (https://spec.commonmark.org/0.31.2/#list-items); a word stands in for it.
                $visible[$index] = rtrim($line).' hidden';
            }
        }

        return $visible;
    }
}
