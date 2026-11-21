<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

final readonly class AttributionLines
{
    // A co-author trailer, and a line that says a tool generated the change (an emoji or other mark may come first), because a build's commits credit no one but their author; bytes, not UTF-8, so a message in another encoding is read too.
    private const string ATTRIBUTION = '/^(?:[ \t]*co-authored-by[ \t]*:|[^A-Za-z0-9]*generated[ -](?:with|by)\b)/i';

    // Because a removed paragraph leaves its blank lines behind, spaces and a Windows line ending included.
    private const string BLANK_LINES = '/\r?\n(?:[ \t\r]*\n){2,}/';

    public static function strippedFrom(string $message): string
    {
        $lines = explode("\n", $message);
        $kept = array_filter($lines, static fn (string $line): bool => 1 !== preg_match(self::ATTRIBUTION, $line));

        // Because a commit whose message keeps every line must keep its id.
        if (count($kept) === count($lines)) {
            return $message;
        }

        $ending = self::endingOf($message, $lines, $kept);
        $text = rtrim((string) preg_replace(self::BLANK_LINES, $ending.$ending, implode("\n", $kept)));

        return '' === $text ? '' : $text.$ending;
    }

    /**
     * @param list<string>       $lines
     * @param array<int, string> $kept
     */
    private static function endingOf(string $message, array $lines, array $kept): string
    {
        $terminated = $kept;
        // Because the last piece of the split is the only one no line ending followed.
        unset($terminated[count($lines) - 1]);

        // So a message written with Windows line endings does not come back with mixed ones. Judged from the endings the kept lines had in the message, because a removed trailer's ending is not the message's; only when no kept line had one (a single line without a final newline is left) does the whole message decide.
        return match (true) {
            array_any($terminated, static fn (string $line): bool => str_ends_with($line, "\r")) => "\r\n",
            [] !== $terminated => "\n",
            default => str_contains($message, "\r\n") ? "\r\n" : "\n",
        };
    }
}
