<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Plain code reads the shape, because a model must not decide whether a bug task is ready to build.
final readonly class BugReport
{
    private const string HEADING = '/^ {0,3}#{1,6}(?:[ \t]+(?<text>.*?)[ \t]*:?)?(?:[ \t]+#+)?[ \t]*$/';

    private const string UNDERLINE = '/^ {0,3}(?:=+|-+)[ \t]*$/';

    private const string LIST_ITEM = '/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?:[ \t]|$)/';

    private const string BOLD_LABEL = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?(?<mark>\*\*|__)(?<label>.+?)[ \t]*:?[ \t]*\k<mark>[ \t]*:?[ \t]*(?<rest>.*)$/';

    // Any indent and list markers before it, because a fence nested in a list item still holds an example.
    private const string FENCE_OPENS = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)*(?<fence>`{3,}|~{3,})(?<info>.*)$/';

    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*$/';

    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*$/';

    private const string COMMENT = '/<!--.*?-->/';

    // The text after the closing marker stays, because GitHub shows it.
    private const string COMMENT_CLOSES = '/-->(?<rest>.*)$/';

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

        foreach (self::withUnderlinedHeadingsMarked(self::linesToRead($body)) as $line) {
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
     * @return list<string>
     */
    private static function linesToRead(string $body): array
    {
        $kept = [];
        $closing = null;

        // Without code, quoted outside text and comments, read in the order they open, because a part written there is an example or a quote, not the task's own statement.
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            if (null !== $closing) {
                $closes = 1 === preg_match($closing, $line, $found);
                $closing = $closes ? null : $closing;

                if (!$closes || !isset($found['rest'])) {
                    $kept[] = '';

                    continue;
                }

                $line = $found['rest'];
            } elseif (null !== ($closing = self::closingOf($line))) {
                $kept[] = '';

                continue;
            }

            $line = (string) preg_replace(self::COMMENT, '', $line);
            $opens = strpos($line, '<!--');

            if (false !== $opens) {
                $line = substr($line, 0, $opens);
                $closing = self::COMMENT_CLOSES;
            }

            $kept[] = $line;
        }

        return $kept;
    }

    // A fence closes only with the same character at least as often, because CommonMark lets a longer fence hold a shorter one.
    private static function closingOf(string $line): ?string
    {
        if (1 === preg_match(self::EXTERNAL_OPENS, $line)) {
            return self::EXTERNAL_CLOSES;
        }

        if (1 !== preg_match(self::FENCE_OPENS, $line, $found)) {
            return null;
        }

        // CommonMark reads backticks after a backtick fence as an inline code span, not as a fence.
        if ('`' === $found['fence'][0] && str_contains($found['info'], '`')) {
            return null;
        }

        return sprintf('/^[ \t]*%s{%d,}[ \t]*$/', preg_quote($found['fence'][0], '/'), strlen($found['fence']));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function withUnderlinedHeadingsMarked(array $lines): array
    {
        foreach ($lines as $index => $line) {
            $start = self::paragraphStart($lines, $index);

            if (null === $start || 1 !== preg_match(self::UNDERLINE, $line)) {
                continue;
            }

            $text = implode(' ', array_map(trim(...), array_slice($lines, $start, $index - $start)));
            array_splice($lines, $start, $index - $start + 1, ['# '.$text, ...array_fill(0, $index - $start, '')]);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function paragraphStart(array $lines, int $index): ?int
    {
        $start = $index;

        // An underline makes a heading only of the paragraph right above it, because after a blank line, a heading or a list item it is a rule.
        while ($start > 0 && '' !== trim($lines[$start - 1]) && 1 !== preg_match(self::HEADING, $lines[$start - 1])) {
            if (1 === preg_match(self::LIST_ITEM, $lines[$start - 1])) {
                return null;
            }

            --$start;
        }

        return $start < $index ? $start : null;
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
