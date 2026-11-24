<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Plain code reads the shape, because a model must not decide whether a bug task is ready to build.
final readonly class BugReport
{
    // Also after list markers, because a heading in a list item still starts a new section.
    private const string HEADING = '/^(?: {0,3}|[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)+)#{1,6}(?:[ \t]+(?<text>.*?)[ \t]*:?)?(?:[ \t]+#+)?[ \t]*$/';

    private const string UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';

    private const string LIST_ITEM = '/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?:[ \t]|$)/';

    private const string BOLD_LABEL = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?(?<mark>\*\*|__)(?<label>.+?)[ \t]*:?[ \t]*\k<mark>[ \t]*:?[ \t]*(?<rest>.*)$/';

    /**
     * @param array<string, string> $texts
     */
    private function __construct(private array $texts)
    {
    }

    public static function fromBody(string $body): self
    {
        $texts = array_fill_keys(array_column(BugPart::cases(), 'value'), '');
        $current = null;

        // Without code, quoted outside text and comments, because a part written there is an example or a quote, not the task's own statement.
        foreach (self::withUnderlinedHeadingsMarked(ReadableLines::of($body)->lines) as $line) {
            $label = self::labelOf($line);

            // Any heading or bold label ends the part, also one of another section, so its text never counts as the part's.
            if (null !== $label) {
                $current = self::partNamed($label[0])?->value;
                $line = $label[1];
            }

            if (null !== $current) {
                $texts[$current] .= $line."\n";
            }
        }

        return new self($texts);
    }

    /**
     * @return list<BugPart>
     */
    public function missingParts(): array
    {
        return array_values(array_filter(BugPart::cases(), fn (BugPart $part): bool => '' === trim($this->texts[$part->value])));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function withUnderlinedHeadingsMarked(array $lines): array
    {
        $start = null;
        $listItem = -1;

        foreach ($lines as $index => $line) {
            $heading = self::underlinedFrom($line, $start, $listItem);

            if (null !== $heading) {
                $text = implode(' ', array_map(trim(...), array_slice($lines, $heading, $index - $heading)));
                array_splice($lines, $heading, $index - $heading + 1, ['# '.$text, ...array_fill(0, $index - $heading, '')]);
                $start = null;

                continue;
            }

            $start = '' === trim($line) || 1 === preg_match(self::HEADING, $line) ? null : $start ?? $index;
            $listItem = 1 === preg_match(self::LIST_ITEM, $line) ? $index : $listItem;
        }

        return $lines;
    }

    private static function underlinedFrom(string $line, ?int $start, int $listItem): ?int
    {
        // An underline makes a heading only of the paragraph right above it, because after a blank line, a heading or in a paragraph with a list item it is a rule.
        return null !== $start && $listItem < $start && 1 === preg_match(self::UNDERLINE, $line) ? $start : null;
    }

    /**
     * @return ?array{string, string}
     */
    private static function labelOf(string $line): ?array
    {
        if (1 === preg_match(self::HEADING, $line, $found)) {
            return [$found['text'] ?? '', ''];
        }

        return 1 === preg_match(self::BOLD_LABEL, $line, $found) ? [$found['label'], $found['rest']] : null;
    }

    private static function partNamed(string $label): ?BugPart
    {
        return array_find(BugPart::cases(), static fn (BugPart $part): bool => 0 === strcasecmp($part->value, $label));
    }
}
