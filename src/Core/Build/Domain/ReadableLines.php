<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// Reads the Markdown blocks that hide or make headings: code fences, indented code, ATX and setext headings and the seven HTML block kinds, because the body goes to the forge as written; block quotes, lazy continuation lines and link reference definitions are not read.
final readonly class ReadableLines
{
    private const string BLOCK_TAGS = 'address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul';
    private const string ATTRIBUTE = '\s+[A-Za-z_:][A-Za-z0-9_.:-]*(?:\s*=\s*(?:[^\s"\'=<>`]+|\'[^\']*\'|"[^"]*"))?';
    private const string BLANK = 'blank';
    // The text that ends a block of kinds 1 to 5, keyed by its end pattern, so a block left open at the end can be closed.
    private const array CLOSERS = [
        '#</(?:pre|script|style|textarea)>#i' => '</pre>',
        '/-->/' => '-->',
        '/\?>/' => '?>',
        '/>/' => '>',
        '/\]\]>/' => ']]>',
    ];

    /**
     * @param list<string> $lines
     *
     * @return array{0: array<int, array{int, string}>, 1: ?string} the headings and the text that closes a fence or HTML block left open
     */
    public function scanned(array $lines): array
    {
        $headings = [];
        $state = ['fence' => null, 'html' => null, 'paragraph' => null];

        foreach ($lines as $number => $line) {
            [$state, $heading] = $this->step($line, $number, $state);

            if (null !== $heading) {
                $headings[$heading[0]] = [$heading[1], $heading[2]];
            }
        }

        return [$headings, $state['fence'] ?? (null === $state['html'] ? null : (self::CLOSERS[$state['html']] ?? null))];
    }

    /**
     * @param array{fence: ?string, html: ?string, paragraph: ?array{int, string}} $state
     *
     * @return array{0: array{fence: ?string, html: ?string, paragraph: ?array{int, string}}, 1: ?array{int, int, string}}
     */
    private function step(string $line, int $number, array $state): array
    {
        if (null !== $state['fence']) {
            $state['fence'] = $this->closes($line, $state['fence']) ? null : $state['fence'];

            return [$state, null];
        }

        if (null !== $state['html']) {
            return [$this->htmlAfter($line, $state), null];
        }

        if ($this->blank($line)) {
            return [$this->withParagraph($state, null), null];
        }

        // Spaces before a tab reach column four too, so a tab after up to three spaces is indentation as well.
        if (1 === preg_match('/^(?: {4,}| {0,3}\t)/', $line)) {
            // Indented code cannot interrupt a paragraph, so inside one the line is only more text of it.
            return [$this->continued($state, $line), null];
        }

        return $this->blockAt($line, $number, $state);
    }

    // A fence closes only on the same character repeated at least as often as the opening line, because any other line is still code.
    private function closes(string $line, string $fence): bool
    {
        return 1 === preg_match('/^ {0,3}('.preg_quote($fence[0], '/').'+)[ \t]*$/', $line, $match) && strlen($match[1]) >= strlen($fence);
    }

    /**
     * @param array{fence: ?string, html: ?string, paragraph: ?array{int, string}} $state
     *
     * @return array{fence: ?string, html: ?string, paragraph: ?array{int, string}}
     */
    private function htmlAfter(string $line, array $state): array
    {
        $ends = self::BLANK === $state['html'] ? $this->blank($line) : 1 === preg_match($state['html'] ?? '', $line);

        return $this->withParagraph($state, null, $ends ? null : $state['html']);
    }

    /**
     * @param array{fence: ?string, html: ?string, paragraph: ?array{int, string}} $state
     * @param ?array{int, string}                                                  $paragraph
     *
     * @return array{fence: ?string, html: ?string, paragraph: ?array{int, string}}
     */
    private function withParagraph(array $state, ?array $paragraph, ?string $html = null): array
    {
        return ['fence' => $state['fence'], 'html' => $html, 'paragraph' => $paragraph];
    }

    /**
     * @param array{fence: ?string, html: ?string, paragraph: ?array{int, string}} $state
     *
     * @return array{0: array{fence: ?string, html: ?string, paragraph: ?array{int, string}}, 1: ?array{int, int, string}}
     */
    private function blockAt(string $line, int $number, array $state): array
    {
        $paragraph = $state['paragraph'];
        $fence = $this->opening($line);

        if (null !== $fence) {
            return [['fence' => $fence, 'html' => null, 'paragraph' => null], null];
        }

        $atx = $this->headingOf($line);

        if (null !== $atx) {
            return [$this->withParagraph($state, null), [$number, $atx[0], $atx[1]]];
        }

        $level = $this->underlineLevel($line);

        if (null !== $paragraph && null !== $level) {
            return [$this->withParagraph($state, null), [$paragraph[0], $level, $paragraph[1]]];
        }

        if (1 === preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line)) {
            return [$this->withParagraph($state, null), null];
        }

        $end = $this->htmlEnd($line, $paragraph);

        if (null !== $end) {
            return [$this->htmlAfter($line, $this->withParagraph($state, null, $end)), null];
        }

        $text = trim($line);

        return [$this->withParagraph($state, null === $paragraph ? [$number, $text] : [$paragraph[0], $paragraph[1].' '.$text]), null];
    }

    // Only spaces and tabs make a line blank in CommonMark, so trim() would also take a vertical tab.
    private function blank(string $line): bool
    {
        return 1 === preg_match('/^[ \t]*$/', $line);
    }

    /**
     * @param array{fence: ?string, html: ?string, paragraph: ?array{int, string}} $state
     *
     * @return array{fence: ?string, html: ?string, paragraph: ?array{int, string}}
     */
    private function continued(array $state, string $line): array
    {
        $paragraph = $state['paragraph'];

        if (null === $paragraph) {
            return $state;
        }

        return $this->withParagraph($state, [$paragraph[0], $paragraph[1].' '.trim($line)]);
    }

    private function opening(string $line): ?string
    {
        if (1 !== preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match)) {
            return null;
        }

        // A backtick fence's info string holds no backtick, because such a line is an inline code span.
        return '`' === $match[1][0] && str_contains($match[2], '`') ? null : $match[1];
    }

    /**
     * @return ?array{int, string}
     */
    private function headingOf(string $line): ?array
    {
        return 1 === preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $heading) ? [strlen($heading[1]), $heading[2] ?? ''] : null;
    }

    private function underlineLevel(string $line): ?int
    {
        if (1 === preg_match('/^ {0,3}=+[ \t]*$/', $line)) {
            return 1;
        }

        return 1 === preg_match('/^ {0,3}-+[ \t]*$/', $line) ? 2 : null;
    }

    // Kinds 6 and 7 end at a blank line, which is not part of the block, so the pattern for them is the marker word.
    /**
     * @param ?array{int, string} $paragraph
     */
    private function htmlEnd(string $line, ?array $paragraph): ?string
    {
        $kinds = [
            '#^ {0,3}<(?:pre|script|style|textarea)(?:[ \t>]|$)#i' => '#</(?:pre|script|style|textarea)>#i',
            '/^ {0,3}<!--/' => '/-->/',
            '/^ {0,3}<\?/' => '/\?>/',
            '/^ {0,3}<![A-Za-z]/' => '/>/',
            '/^ {0,3}<!\[CDATA\[/' => '/\]\]>/',
            '#^ {0,3}</?(?:'.self::BLOCK_TAGS.')(?:[ \t]|/?>|$)#i' => self::BLANK,
        ];

        foreach ($kinds as $start => $end) {
            if (1 === preg_match($start, $line)) {
                return $end;
            }
        }

        // A lone tag cannot interrupt a paragraph, because it is then inline HTML of that paragraph.
        return null === $paragraph && 1 === preg_match('#^ {0,3}(?:<[A-Za-z][A-Za-z0-9-]*(?:'.self::ATTRIBUTE.')*\s*/?>|</[A-Za-z][A-Za-z0-9-]*\s*>)[ \t]*$#', $line) ? self::BLANK : null;
    }
}
