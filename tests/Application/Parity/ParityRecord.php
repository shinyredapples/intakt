<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

// Because a done parity item counts toward the takeover only when the Intakt tests it names exist.
final readonly class ParityRecord
{
    // Because these sections have their Intakt tests recorded, every item in them is judged, open or done (#3102).
    public const array JUDGED_SECTIONS = ['Intake and shaping', 'Build and hand-back', 'CI'];

    // GitHub renders `[X]` as checked too, so a capital X must not escape the check.
    private const string DONE_ITEM = '/^\s*- \[[xX]\] /';

    private const string OPEN_ITEM = '/^\s*- \[ \] (.*)$/';

    // Only a top or second level heading starts a section, so a deeper heading cannot switch judging off inside one.
    private const string SECTION_HEADING = '/^#{1,2}(?:\s+(.*))?$/';

    private const string INTAKT_PART = 'Intakt:';

    private const string NOT_BUILT = 'not built yet';

    private const string FIRST_SENTENCE = '/^(.*?\.)(?:\s|$)/';

    // Because any other line could hide a name, only a blank line, a list item or a heading (not an issue number such as `#12`) ends a done item.
    private const string ITEM_BOUNDARY = '/^\s*(?:$|[-*+] |\d+[.)] |#{1,6}(?:\s|$))/';

    // Outside a span a backslash escapes the next character, so `\`` opens no span; a run of backticks no equal run closes is unclosed.
    private const string BACKTICKED = '/\\\\.|(`++)(.*?)(?<!`)\1(?!`)|`++/';

    private const string NAMED_TEST = '/^(?:(\w+Test)::)?(test\w+)$/';

    // A token with such a word is meant as a test name, so a near miss like `SomeTest.testName` is reported, not skipped.
    private const string TEST_WORD = '/\w*Test\b|\btest\w*/';

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
        $testClasses = TestClassIndex::of($this->testsDirectory, $this->testsNamespace);
        $findings = [];
        $continuation = null;

        foreach ($this->linesWithSections($record) as [$number, $line, $section]) {
            if (null !== $continuation && 1 !== preg_match(self::ITEM_BOUNDARY, $line)) {
                $findings[] = sprintf('line %d: %s', $number, $continuation);
            }

            $continuation = $this->continuationProblemAfter($line, $section);
            foreach ($this->problemsOf($line, $section, $testClasses) as $problem) {
                $findings[] = sprintf('line %d: %s', $number, $problem);
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    public function notBuiltItems(string $record): array
    {
        $items = [];
        foreach ($this->linesWithSections($record) as [, $line, $section]) {
            if ($this->isJudgedOpenItem($line, $section) && $this->saysNotBuilt($line)) {
                $items[] = sprintf('%s: %s', $section, $this->nameOf($line));
            }
        }

        return $items;
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
    private function problemsOf(string $line, ?string $section, TestClassIndex $testClasses): array
    {
        if (1 === preg_match(self::DONE_ITEM, $line)) {
            return $this->doneItemProblems($line, $testClasses);
        }

        if (!$this->isJudgedOpenItem($line, $section)) {
            return [];
        }

        $problems = $this->namedTestProblems($this->namedTests($line), $testClasses);
        if (!$this->saysNotBuilt($line)) {
            $problems[] = sprintf('an open item must say after `Intakt:` what is %s', self::NOT_BUILT);
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function doneItemProblems(string $item, TestClassIndex $testClasses): array
    {
        $namedTests = $this->namedTests($item);
        if ([] === $namedTests) {
            return ['names no Intakt test after `Intakt:`'];
        }

        return $this->namedTestProblems($namedTests, $testClasses);
    }

    /**
     * @param list<string|array{?string, string}> $namedTests
     *
     * @return list<string>
     */
    private function namedTestProblems(array $namedTests, TestClassIndex $testClasses): array
    {
        $problems = [];
        foreach ($namedTests as $namedTest) {
            $problem = is_string($namedTest) ? $namedTest : $testClasses->problemWith($namedTest[0], $namedTest[1]);
            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private function isJudgedOpenItem(string $line, ?string $section): bool
    {
        return in_array($section, self::JUDGED_SECTIONS, true) && 1 === preg_match(self::OPEN_ITEM, $line);
    }

    private function saysNotBuilt(string $item): bool
    {
        $start = strpos($item, self::INTAKT_PART);

        return false !== $start && str_contains(substr($item, $start), self::NOT_BUILT);
    }

    private function nameOf(string $item): string
    {
        preg_match(self::OPEN_ITEM, $item, $text);
        $text = $text[1] ?? '';

        return 1 === preg_match(self::FIRST_SENTENCE, $text, $sentence) ? $sentence[1] : $text;
    }

    /**
     * @return list<string|array{?string, string}>
     */
    private function namedTests(string $item): array
    {
        $start = strpos($item, self::INTAKT_PART);
        if (false === $start) {
            return [];
        }

        $class = null;
        $namedTests = [];
        foreach ($this->codeSpans(substr($item, $start)) as $span) {
            $namedTest = null === $span
                ? 'has a backtick after `Intakt:` that is never closed'
                : $this->namedTestIn($span, $class);
            if (is_array($namedTest)) {
                $class = $namedTest[0];
            }

            if (null !== $namedTest) {
                $namedTests[] = $namedTest;
            }
        }

        return $namedTests;
    }

    /**
     * @return list<?string>
     */
    private function codeSpans(string $text): array
    {
        preg_match_all(self::BACKTICKED, $text, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $spans = [];
        foreach ($tokens as $token) {
            if (null !== $token[1]) {
                $spans[] = trim((string) $token[2]);
            } elseif (str_starts_with($token[0], '`')) {
                $spans[] = null;
            }
        }

        return $spans;
    }

    /**
     * @return string|array{?string, string}|null
     */
    private function namedTestIn(string $span, ?string $previousClass): string|array|null
    {
        if (1 === preg_match(self::NAMED_TEST, $span, $match)) {
            return ['' !== $match[1] ? $match[1] : $previousClass, $match[2]];
        }

        if (1 === preg_match(self::TEST_WORD, $span)) {
            return sprintf('`%s` is not a test name of the form `SomeTest::testName`', $span);
        }

        return null;
    }
}
