<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Because a done parity item counts toward the takeover only when the Intakt tests it names exist.
final readonly class ParityRecord
{
    // GitHub renders `[X]` as checked too, so a capital X must not escape the check.
    private const string DONE_ITEM = '/^- \[[xX]\] /';

    private const string INTAKT_PART = 'Intakt:';

    // Only `SomeTest::testName` and a bare `testName` count, so a backticked command or class in a remark is no test name.
    private const string NAMED_TEST = '/`(?:(\w+Test)::)?(test\w+)`/';

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
            if (preg_match(self::DONE_ITEM, $line) !== 1) {
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
        if ($namedTests === []) {
            return ['names no Intakt test after `Intakt:`'];
        }

        $problems = [];
        foreach ($namedTests as [$class, $method]) {
            $problems[] = $this->problemWith($class, $method, $testClasses);
        }

        return array_values(array_filter($problems));
    }

    /**
     * @return list<array{?string, string}>
     */
    private function namedTests(string $item): array
    {
        $start = strpos($item, self::INTAKT_PART);
        if ($start === false) {
            return [];
        }

        preg_match_all(self::NAMED_TEST, substr($item, $start), $matches, PREG_SET_ORDER);

        $class = null;
        $namedTests = [];
        foreach ($matches as $match) {
            $class = $match[1] !== '' ? $match[1] : $class;
            $namedTests[] = [$class, $match[2]];
        }

        return $namedTests;
    }

    /**
     * @param array<string, list<string>> $testClasses
     */
    private function problemWith(?string $class, string $method, array $testClasses): ?string
    {
        if ($class === null) {
            return sprintf('`%s` follows no test class', $method);
        }

        $candidates = $testClasses[$class] ?? [];
        if (count($candidates) > 1) {
            return sprintf('%s names more than one test class: %s', $class, implode(', ', $candidates));
        }

        $fullName = array_first($candidates);
        if ($fullName === null || !class_exists($fullName)) {
            return sprintf('%s is no test class under tests/', $class);
        }

        if (!method_exists($fullName, $method)) {
            return sprintf('%s::%s is no test method of %s', $class, $method, $fullName);
        }

        return null;
    }
}
