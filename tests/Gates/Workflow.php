<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

final readonly class Workflow
{
    private const string DIRECTORY = __DIR__.'/../../.github/workflows';

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

    public static function runOf(string $step): string
    {
        $block = self::blockOf($step);
        $start = array_search(true, array_map(static fn (string $line): bool => 1 === preg_match('/^\s+run: /', $line), $block), true);

        if (!is_int($start)) {
            return '';
        }

        $first = preg_replace('/^\s+run: \|?/', '', $block[$start]) ?? '';

        return trim(implode("\n", [$first, ...array_map(static fn (string $line): string => substr($line, 10), array_slice($block, $start + 1))]));
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

    /**
     * @return list<string>
     */
    private static function blockOf(string $step): array
    {
        $block = [];
        $inside = false;

        foreach (self::linesOf(self::DIRECTORY.'/ci.yml') as $line) {
            if (1 === preg_match('/^\s+- /', $line)) {
                $inside = str_contains($line, 'name: '.$step);
            }

            if ($inside) {
                $block[] = $line;
            }
        }

        return $block;
    }
}
