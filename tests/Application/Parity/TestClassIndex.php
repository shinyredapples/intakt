<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

// Because parity items name tests by short class name, a name must resolve to exactly one runnable test under tests/.
final readonly class TestClassIndex
{
    // Gate fixtures are inputs of other tests, some of them not even valid PHP, so they are never loaded as test classes.
    private const string FIXTURES = '/Fixtures/';

    /**
     * @param array<string, list<string>> $classesByShortName
     */
    private function __construct(
        private array $classesByShortName,
    ) {
    }

    public static function of(string $testsDirectory, string $testsNamespace): self
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDirectory, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($testsDirectory));
            if (!str_ends_with($path, 'Test.php') || str_contains($path, self::FIXTURES)) {
                continue;
            }

            $classes[$file->getBasename('.php')][] = $testsNamespace.str_replace('/', '\\', substr($path, 0, -4));
        }

        return new self($classes);
    }

    public function problemWith(?string $class, string $method): ?string
    {
        if (null === $class) {
            return sprintf('`%s` follows no test class', $method);
        }

        $candidates = $this->classesByShortName[$class] ?? [];
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
