<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

final readonly class AttributionLines
{
    // A co-author trailer, and a line that says a tool generated the change (an emoji or other mark may come first), because a build's commits credit no one but their author; bytes, not UTF-8, so a message in another encoding is read too.
    private const string ATTRIBUTION = '/^(?:[ \t]*co-authored-by[ \t]*:|[^A-Za-z0-9]*generated[ -](?:with|by)\b)/i';

    // Blank lines a removed paragraph leaves behind, spaces and a Windows line ending included.
    private const string BLANK_LINES = '/\n(?:[ \t\r]*\n){2,}/';

    public static function strippedFrom(string $message): string
    {
        $lines = explode("\n", $message);
        $kept = array_filter($lines, static fn (string $line): bool => 1 !== preg_match(self::ATTRIBUTION, $line));

        // Because a commit whose message keeps every line must keep its id.
        if (count($kept) === count($lines)) {
            return $message;
        }

        $text = rtrim((string) preg_replace(self::BLANK_LINES, "\n\n", implode("\n", $kept)));

        return '' === $text ? '' : $text."\n";
    }
}
