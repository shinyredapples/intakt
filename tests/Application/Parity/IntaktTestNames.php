<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

// Because a parity item proves nothing with a name no test class holds, each name after `Intakt:` is looked up.
final readonly class IntaktTestNames
{
    public const string INTAKT_PART = 'Intakt:';

    // Outside a span a backslash escapes the next character, so `\`` opens no span; a run of backticks no equal run closes is unclosed.
    private const string BACKTICKED = '/\\\\.|(`++)(.*?)(?<!`)\1(?!`)|`++/';

    private const string NAMED_TEST = '/^(?:(\w+Test)::)?(test\w+)$/';

    // A token with such a word is meant as a test name, so a near miss like `SomeTest.testName` is reported, not skipped.
    private const string TEST_WORD = '/\w*Test\b|\btest\w*/';

    public function __construct(
        private TestClassIndex $testClasses,
    ) {
    }

    // Null rather than no problems, because an item that names no test is a finding only when it is done.
    /**
     * @return list<string>|null
     */
    public function problemsOf(string $item): ?array
    {
        $namedTests = $this->namedTests($item);
        if ([] === $namedTests) {
            return null;
        }

        $problems = [];
        foreach ($namedTests as $namedTest) {
            $problem = is_string($namedTest) ? $namedTest : $this->testClasses->problemWith($namedTest[0], $namedTest[1]);
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
