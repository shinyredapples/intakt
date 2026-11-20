<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

// Because a done parity item counts toward the takeover only when the Intakt tests it names exist.
final readonly class ParityRecord
{
    // GitHub renders `[X]` as checked too, so a capital X must not escape the check.
    private const string DONE_ITEM = '/^\s*- \[[xX]\] /';

    private const string INTAKT_PART = 'Intakt:';

    // Only `SomeTest::testName` and a bare `testName` count, so a backticked command or class in a remark is no test name.
    private const string BACKTICKED = '/`([^`]*)`/';

    private const string NAMED_TEST = '/^(?:(\w+Test)::)?(test\w+)$/';

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

        foreach (explode("\n", $record) as $index => $line) {
            if (1 !== preg_match(self::DONE_ITEM, $line)) {
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
        foreach ($namedTests as [$class, $method, $malformed]) {
            if (null !== $malformed) {
                $problems[] = sprintf('`%s` %s', $method, $malformed);

                continue;
            }

            $problem = $this->problemWith($class, $method, $testClasses);
            if (null !== $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return list<array{?string, string, ?string}>
     */
    private function namedTests(string $item): array
    {
        $start = strpos($item, self::INTAKT_PART);
        if (false === $start) {
            return [];
        }

        preg_match_all(self::BACKTICKED, substr($item, $start), $tokens);

        $class = null;
        $namedTests = [];
        foreach (array_filter($tokens[1], $this->looksLikeATestName(...)) as $token) {
            if (1 !== preg_match(self::NAMED_TEST, $token, $match)) {
                $namedTests[] = [null, $token, 'is not a test name of the form `SomeTest::testName`'];

                continue;
            }

            $class = '' !== $match[1] ? $match[1] : $class;
            $namedTests[] = [$class, $match[2], null];
        }

        return $namedTests;
    }

    private function looksLikeATestName(string $token): bool
    {
        return str_contains($token, '::') || str_starts_with($token, 'test');
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

        if (!method_exists($fullName, $method) || !new ReflectionMethod($fullName, $method)->isPublic()) {
            return sprintf('%s::%s is no test method of %s', $class, $method, $fullName);
        }

        return null;
    }
}
