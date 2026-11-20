<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

// Because a done parity item counts toward the takeover only when the Intakt tests it names exist.
final readonly class ParityRecord
{
    // GitHub renders `[X]` as checked too, so a capital X must not escape the check.
    private const string DONE_ITEM = '/^\s*- \[[xX]\] /';

    private const string INTAKT_PART = 'Intakt:';

    // A boundary is a blank line, a list item or a heading; any other line after a done item could hide a name from the check.
    private const string ITEM_BOUNDARY = '/^\s*(?:$|[-*+] |\d+[.)] |#)/';

    // Outside a span a backslash escapes the next character, so `\`` opens no span; a run of backticks no equal run closes is unclosed.
    private const string BACKTICKED = '/\\\\.|(`++)(.*?)(?<!`)\1(?!`)|`++/';

    private const string NAMED_TEST = '/^(?:(\w+Test)::)?(test\w+)$/';

    // A token with such a word is meant as a test name, so a near miss like `SomeTest.testName` is reported, not skipped.
    private const string TEST_WORD = '/\w*Test\b|\btest\w*/';

    // Gate fixtures are inputs of other tests, some of them not even valid PHP, so they are never loaded as test classes.
    private const string FIXTURES = '/Fixtures/';

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
        $testClasses = $this->testClassesByShortName();
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
     * @return array<string, list<string>>
     */
    private function testClassesByShortName(): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->testsDirectory, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($this->testsDirectory));
            if (!str_ends_with($path, 'Test.php') || str_contains($path, self::FIXTURES)) {
                continue;
            }

            $classes[$file->getBasename('.php')][] = $this->testsNamespace.str_replace('/', '\\', substr($path, 0, -4));
        }

        return $classes;
    }

    /**
     * @param array<string, list<string>> $testClasses
     *
     * @return list<string>
     */
    private function problemsOf(string $item, array $testClasses): array
    {
        $namedTests = $this->namedTests($item);
        if ([] === $namedTests) {
            return ['names no Intakt test after `Intakt:`'];
        }

        $problems = [];
        foreach ($namedTests as $namedTest) {
            $problem = is_string($namedTest) ? $namedTest : $this->problemWith($namedTest[0], $namedTest[1], $testClasses);
            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return list<string|array{?string, string}> a name to resolve, or a problem the text itself already shows
     */
    private function namedTests(string $item): array
    {
        $start = strpos($item, self::INTAKT_PART);
        if (false === $start) {
            return [];
        }

        preg_match_all(self::BACKTICKED, substr($item, $start), $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $class = null;
        $namedTests = [];
        foreach ($tokens as $token) {
            if (null === $token[1]) {
                if (str_starts_with($token[0], '`')) {
                    $namedTests[] = 'has a backtick after `Intakt:` that is never closed';
                }

                continue;
            }

            $name = trim((string) $token[2]);
            if (1 === preg_match(self::NAMED_TEST, $name, $match)) {
                $class = '' !== $match[1] ? $match[1] : $class;
                $namedTests[] = [$class, $match[2]];
            } elseif (1 === preg_match(self::TEST_WORD, $name)) {
                $namedTests[] = sprintf('`%s` is not a test name of the form `SomeTest::testName`', $name);
            }
        }

        return $namedTests;
    }

    /**
     * @param array<string, list<string>> $testClasses
     */
    private function problemWith(?string $class, string $method, array $testClasses): ?string
    {
        if (null === $class) {
            return sprintf('`%s` follows no test class', $method);
        }

        $candidates = $testClasses[$class] ?? [];
        sort($candidates);
        if (count($candidates) > 1) {
            return sprintf('%s names more than one test class: %s', $class, implode(', ', $candidates));
        }

        $fullName = array_first($candidates);
        if (null === $fullName || !class_exists($fullName)) {
            return sprintf('%s is no test class under tests/', $class);
        }

        $reflection = new ReflectionClass($fullName);
        if ($reflection->isAbstract() || !$reflection->isSubclassOf(TestCase::class)) {
            return sprintf('%s is no runnable test class', $class);
        }

        if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
            return sprintf('%s::%s is no test method of %s', $class, $method, $fullName);
        }

        return null;
    }
}
