<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Plain code reads the shape, because a model must not decide whether a bug task is ready to build.
final readonly class BugReport
{
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

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            $start = self::startOf($line);

            if (null !== $start) {
                $current = $start[0]->value;
                $texts[$current] .= $start[1]."\n";

                continue;
            }

            // A heading of another section ends the part, so its text never counts as the part's.
            $current = 1 === preg_match('/^\s*#{1,6}\s/', $line) ? null : $current;

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
     * @return ?array{BugPart, string}
     */
    private static function startOf(string $line): ?array
    {
        // A label counts as a heading, as a bold label or as a label with a colon, because a sentence that only starts with "When" must not.
        $labels = '(?:'.implode('|', array_map(static fn (BugPart $part): string => preg_quote($part->value, '/'), BugPart::cases())).')(?!\w)';
        $pattern = sprintf(
            '/^\s*(?:#{1,6}\s+(?<h>%1$s)\s*:?\s*|(?:[-*]\s+)?(?:\*\*|__)(?<b>%1$s)\s*:?(?:\*\*|__)\s*:?\s*|(?:[-*]\s+)?(?<c>%1$s)\s*:\s*)(?<rest>.*)$/i',
            $labels,
        );

        if (1 !== preg_match($pattern, $line, $found)) {
            return null;
        }

        $label = '' !== $found['h'] ? $found['h'] : ('' !== $found['b'] ? $found['b'] : $found['c']);

        foreach (BugPart::cases() as $part) {
            if (0 === strcasecmp($part->value, $label)) {
                return [$part, $found['rest']];
            }
        }

        return null;
    }
}
