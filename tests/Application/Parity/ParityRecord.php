<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

// Because a done parity item counts toward the takeover only when the Intakt tests it names exist.
final readonly class ParityRecord
{
    // GitHub renders `[X]` as checked too, so a capital X must not escape the check.
    private const string DONE_ITEM = '/^\s*- \[[xX]\] /';

    private const string INTAKT_PART = 'Intakt:';

    // Because any other line after a done item could hide a name from the check, only a blank line, a list item or a heading ends one.
    private const string ITEM_BOUNDARY = '/^\s*(?:$|[-*+] |\d+[.)] |#)/';

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
        $afterDoneItem = false;

        foreach (explode("\n", $record) as $index => $line) {
            if ($afterDoneItem && 1 !== preg_match(self::ITEM_BOUNDARY, $line)) {
                $findings[] = sprintf('line %d: a done item must stay on one line', $index + 1);
            }

            $afterDoneItem = 1 === preg_match(self::DONE_ITEM, $line);
            if (!$afterDoneItem) {
                continue;
            }

            foreach ($this->problemsOf($line, $testClasses) as $problem) {
                $findings[] = sprintf('line %d: %s', $index + 1, $problem);
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function problemsOf(string $item, TestClassIndex $testClasses): array
    {
        $namedTests = $this->namedTests($item);
        if ([] === $namedTests) {
            return ['names no Intakt test after `Intakt:`'];
        }

        $problems = [];
        foreach ($namedTests as $namedTest) {
            $problem = is_string($namedTest) ? $namedTest : $testClasses->problemWith($namedTest[0], $namedTest[1]);
            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
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
