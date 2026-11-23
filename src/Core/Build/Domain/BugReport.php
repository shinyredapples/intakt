<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Plain code reads the shape, because a model must not decide whether a bug task is ready to build.
final readonly class BugReport
{
    private const string HEADING = '/^ {0,3}#{1,6}(?:[ \t]+(?<text>.*?)[ \t]*:?)?(?:[ \t]+#+)?[ \t]*$/';

    private const string BOLD_LABEL = '/^[ \t]*(?:[-*+][ \t]+)?(?<mark>\*\*|__)(?<label>.+?)[ \t]*:?[ \t]*\k<mark>[ \t]*:?[ \t]*(?<rest>.*)$/';

    private const string FENCE_OPENS = '/^ {0,3}(?<fence>`{3,}|~{3,})/';

    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*$/';

    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*$/';

    // To the end of the body when it is not closed, because GitHub then hides the rest too.
    private const string COMMENT = '/<!--.*?(?:-->|\z)/s';

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

        foreach (self::linesToRead($body) as $line) {
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

        // Without code, quoted outside text and comments, because a part written there is an example or a quote, not the task's own statement.
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            if (null !== $closing) {
                $closing = 1 === preg_match($closing, $line) ? null : $closing;

                continue;
            }

            $closing = self::closingOf($line);

            if (null === $closing) {
                $kept[] = $line;
            }
        }

        return explode("\n", (string) preg_replace(self::COMMENT, '', implode("\n", $kept)));
    }

    // The pattern of the line that closes the block this line opens, or null for a line that opens none.
    private static function closingOf(string $line): ?string
    {
        if (1 === preg_match(self::EXTERNAL_OPENS, $line)) {
            return self::EXTERNAL_CLOSES;
        }

        if (1 !== preg_match(self::FENCE_OPENS, $line, $found)) {
            return null;
        }

        return sprintf('/^ {0,3}%s{%d,}[ \t]*$/', preg_quote($found['fence'][0], '/'), strlen($found['fence']));
    }

    /**
     * @return ?array{string, string} the label and the text after it on its line
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
