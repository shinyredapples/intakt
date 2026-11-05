<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PhpStartupOptions
{
    private const string COMMAND_LINE = '/proc/self/cmdline';

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public static function current(array $arguments): array
    {
        // Read from /proc because PHP exposes its own options (-d, -n, -c) nowhere else.
        $commandLine = is_readable(self::COMMAND_LINE) ? file_get_contents(self::COMMAND_LINE) : false;

        return false === $commandLine ? [] : self::between(explode("\0", rtrim($commandLine, "\0")), $arguments);
    }

    /**
     * @param list<string> $commandLine
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public static function between(array $commandLine, array $arguments): array
    {
        // Counted from the end, so an option value that equals the script path stays an option.
        $optionCount = count($commandLine) - count($arguments) - 1;

        if ($optionCount < 1 || array_slice($commandLine, $optionCount + 1) !== $arguments) {
            return [];
        }

        return array_slice($commandLine, 1, $optionCount);
    }
}
