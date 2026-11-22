<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightLayout
{
    public const string FILE = 'docs/highlights.d/layout.md';

    public const string SECTION_HEADING = '/^## (.+)$/';

    public const string SEPARATOR_ROW = '/^\|?(?:\s*:?-+:?\s*\|)*\s*:?-+:?\s*\|?$/';

    /**
     * @param list<string>                                                      $preamble
     * @param array<string, array{lines: list<string>, kind: HighlightRowKind}> $sections
     */
    private function __construct(
        private array $preamble,
        private array $sections,
    ) {
    }

    /**
     * @param list<string> $lines
     */
    public static function fromLines(array $lines): self
    {
        $preamble = [];
        $bodies = [];
        $current = null;

        foreach ($lines as $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line, $heading)) {
                $current = $heading[1];
                $bodies[$current] = [];

                continue;
            }

            if (null === $current) {
                $preamble[] = $line;

                continue;
            }

            $bodies[$current][] = $line;
        }

        return new self(self::withoutTrailingBlanks($preamble), array_map(self::sectionOf(...), $bodies));
    }

    public function kindOf(string $section): ?HighlightRowKind
    {
        return $this->sections[$section]['kind'] ?? null;
    }

    /**
     * @param array<string, array<string, string>> $rows
     */
    public function assembledWith(array $rows): string
    {
        $lines = [...$this->preamble, ''];

        foreach ($this->sections as $name => $section) {
            $sectionRows = array_values($rows[$name] ?? []);
            $lines = [...$lines, '## '.$name, ...$section['lines']];

            // A list needs a blank line after its heading, while table rows continue the header.
            if (HighlightRowKind::ListItem === $section['kind'] && [] !== $sectionRows) {
                $lines[] = '';
            }

            $lines = [...$lines, ...$sectionRows, ''];
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function withoutTrailingBlanks(array $lines): array
    {
        while ([] !== $lines && '' === trim(array_last($lines))) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $body
     *
     * @return array{lines: list<string>, kind: HighlightRowKind}
     */
    private static function sectionOf(array $body): array
    {
        $lines = self::withoutTrailingBlanks($body);
        $isTable = 1 === preg_match(self::SEPARATOR_ROW, array_last($lines) ?? '');

        return ['lines' => $lines, 'kind' => $isTable ? HighlightRowKind::TableRow : HighlightRowKind::ListItem];
    }
}
