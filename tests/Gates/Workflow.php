<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

final readonly class Workflow
{
    private const string DIRECTORY = __DIR__.'/../../.github/workflows';

    private const string CI_DIRECTORY = __DIR__.'/../../.github/ci';

    /**
     * @return list<string>
     */
    public static function allLines(): array
    {
        $lines = [];

        foreach (self::files() as $file) {
            $lines = [...$lines, ...self::linesOf($file)];
        }

        return $lines;
    }

    public static function ciImageDefinition(): string
    {
        $definition = file_get_contents(self::CI_DIRECTORY.'/Dockerfile');

        return false === $definition ? '' : $definition;
    }

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $files = glob(self::DIRECTORY.'/*.yml');

        return false === $files ? [] : $files;
    }

    /**
     * @return list<string>
     */
    private static function linesOf(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        return false === $lines ? [] : $lines;
    }
}
