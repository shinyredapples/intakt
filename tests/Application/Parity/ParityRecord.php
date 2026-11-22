<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

// Because a done parity item counts toward the takeover only when the Intakt tests it names exist.
final readonly class ParityRecord
{
    // Because every section has its Intakt tests recorded, every item in it is judged, open or done (#3102, #3103); the test checks that each heading of the record is listed here.
    public const array JUDGED_SECTIONS = ['Intake and shaping', 'Build and hand-back', 'CI', 'Reviews', 'Planning and budget', 'Merge and protection', 'Operations'];

    // GitHub renders `[X]` as checked too, so a capital X must not escape the check.
    private const string DONE_ITEM = '/^\s*- \[[xX]\] /';

    private const string OPEN_ITEM = '/^\s*- \[ \] (.*)$/';

    // Only a top or second level heading starts a section, so a deeper heading cannot switch judging off inside one.
    private const string SECTION_HEADING = '/^#{1,2}(?:\s+(.*))?$/';

    // GitHub renders a `*`, `+` or numbered item as a list item too, so in a judged section any item without the exact checkbox is reported, not skipped.
    private const string LIST_ITEM = '/^\s*(?:[-*+]|\d+[.)])(?:\s|$)/';

    // Because the words alone can stand in a sentence that denies them, only the record's own form counts: it names the tasks that build the rest, and it is closed and ends the line, so no later remark can take it back.
    private const string NOT_BUILT_MARKER = 'not built yet(?| \((?:[^()]*, )?(T\d+(?: and T\d+)*)\)|: (?:[^()]*, )?(T\d+(?: and T\d+)*))';

    // A Markdown link target may hold parentheses of its own, so text before the marker may nest them to any depth as long as each one closes.
    private const string BALANCED_TEXT = '(?:[^()]|(?&parenthesized))*';

    // The group is defined after the branch reset so the tasks stay group 1 in both forms.
    private const string PARENTHESIZED = '(?(DEFINE)(?<parenthesized>\((?:[^()]|(?&parenthesized))*\)))';

    // A closing parenthesis is accepted exactly when it closes the remark the marker stands in, so neither a stray one nor an unclosed remark can pass.
    private const string NOT_BUILT = '/^(?|\s*'.self::NOT_BUILT_MARKER.'|'.self::BALANCED_TEXT.'\((?:'.self::BALANCED_TEXT.'; )?'.self::NOT_BUILT_MARKER.'\))\s*$'.self::PARENTHESIZED.'/';

    private const string NOT_BUILT_FORM = 'an open item must say after `Intakt:` what is not built yet, as `not built yet (<what>, T<n>)` or `not built yet: <what>, T<n>`';

    private const string FIRST_SENTENCE = '/^(.*?\.)(?:\s|$)/';

    // Because any other line could hide a name, only a blank line, a list item or a heading (not an issue number such as `#12`) ends a judged item.
    private const string ITEM_BOUNDARY = '/^\s*(?:$|[-*+] |\d+[.)] |#{1,6}(?:\s|$))/';

    public function __construct(
        private string $testsDirectory,
        private string $testsNamespace,
    ) {
    }

    /**
     * @return list<string>
     */
    public function unprovenItems(string $record): array
    {
        $names = new IntaktTestNames(TestClassIndex::of($this->testsDirectory, $this->testsNamespace));
        $findings = [];
        $continuation = null;

        foreach ($this->linesWithSections($record) as [$number, $line, $section]) {
            if (null !== $continuation && 1 !== preg_match(self::ITEM_BOUNDARY, $line)) {
                $findings[] = sprintf('line %d: %s', $number, $continuation);
            }

            $continuation = $this->continuationProblemAfter($line, $section);
            foreach ($this->problemsOf($line, $section, $names) as $problem) {
                $findings[] = sprintf('line %d: %s', $number, $problem);
            }
        }

        return $findings;
    }

    /**
     * @return array<string, list<string>>
     */
    public function notBuiltItems(string $record): array
    {
        $items = [];
        foreach ($this->linesWithSections($record) as [$number, $line, $section]) {
            $tasks = $this->isJudgedOpenItem($line, $section) ? $this->notBuiltTasksOf($line) : null;
            if (null !== $tasks) {
                // The line number keeps two items that start with the same sentence apart, so neither drops out of the list.
                $items[sprintf('%s, line %d: %s', $section, $number, $this->nameOf($line))] = $tasks;
            }
        }

        return $items;
    }

    // Because the first heading is the page title and no section, it is left out.
    /**
     * @return list<string>
     */
    public function sectionHeadings(string $record): array
    {
        $headings = [];
        foreach (explode("\n", $record) as $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line, $heading)) {
                $headings[] = trim($heading[1] ?? '');
            }
        }

        return array_slice($headings, 1);
    }

    /**
     * @return list<array{int, string, ?string}>
     */
    private function linesWithSections(string $record): array
    {
        $section = null;
        $lines = [];
        foreach (explode("\n", $record) as $index => $line) {
            if (1 === preg_match(self::SECTION_HEADING, $line, $heading)) {
                $section = trim($heading[1] ?? '');
            }

            $lines[] = [$index + 1, $line, $section];
        }

        return $lines;
    }

    private function continuationProblemAfter(string $line, ?string $section): ?string
    {
        if (1 === preg_match(self::DONE_ITEM, $line)) {
            return 'a done item must stay on one line';
        }

        return $this->isJudgedOpenItem($line, $section) ? 'an open item of a judged section must stay on one line' : null;
    }

    /**
     * @return list<string>
     */
    private function problemsOf(string $line, ?string $section, IntaktTestNames $names): array
    {
        if (1 === preg_match(self::DONE_ITEM, $line)) {
            return $names->problemsOf($line) ?? ['names no Intakt test after `Intakt:`'];
        }

        if (!in_array($section, self::JUDGED_SECTIONS, true) || 1 !== preg_match(self::LIST_ITEM, $line)) {
            return [];
        }

        if (1 !== preg_match(self::OPEN_ITEM, $line)) {
            return ['a list item of a judged section must start with `- [ ]` or `- [x]`'];
        }

        $problems = $names->problemsOf($line) ?? [];
        if (null === $this->notBuiltTasksOf($line)) {
            $problems[] = self::NOT_BUILT_FORM;
        }

        return $problems;
    }

    private function isJudgedOpenItem(string $line, ?string $section): bool
    {
        return in_array($section, self::JUDGED_SECTIONS, true) && 1 === preg_match(self::OPEN_ITEM, $line);
    }

    /**
     * @return ?list<string>
     */
    private function notBuiltTasksOf(string $item): ?array
    {
        $start = strpos($item, IntaktTestNames::INTAKT_PART);
        if (false === $start || 1 !== preg_match(self::NOT_BUILT, substr($item, $start + strlen(IntaktTestNames::INTAKT_PART)), $status)) {
            return null;
        }

        return explode(' and ', $status[1]);
    }

    private function nameOf(string $item): string
    {
        preg_match(self::OPEN_ITEM, $item, $text);
        $text = $text[1] ?? '';

        return 1 === preg_match(self::FIRST_SENTENCE, $text, $sentence) ? $sentence[1] : $text;
    }
}
