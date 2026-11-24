<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// The rule: the install block is the first code fence after the line that starts with `<!-- install-check`, so the README stays the one source of the commands.
final readonly class ReadmeInstallBlock
{
    private const string MARKER = '<!-- install-check';

    /**
     * @return list<string>
     */
    public static function commandsIn(string $readme): array
    {
        $lines = explode("\n", str_replace("\r", '', $readme));
        $marker = array_find_key($lines, static fn (string $line): bool => str_starts_with($line, self::MARKER));

        if (null === $marker) {
            throw new InstallCheckCouldNotRun('the README has no line starting with "'.self::MARKER.'" above its install block');
        }

        $commands = self::commandsOfBlock(array_slice($lines, $marker + 1));

        return [] === $commands ? throw new InstallCheckCouldNotRun('the README install block is empty') : $commands;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function commandsOfBlock(array $lines): array
    {
        $open = array_find_key($lines, static fn (string $line): bool => '' !== trim($line));

        if (null === $open || !str_starts_with($lines[$open], '```')) {
            throw new InstallCheckCouldNotRun('the README marker is not followed by a code block');
        }

        $body = array_slice($lines, $open + 1);
        $close = array_find_key($body, static fn (string $line): bool => str_starts_with($line, '```'));

        if (null === $close) {
            throw new InstallCheckCouldNotRun('the README install block is not closed');
        }

        $commands = array_map(trim(...), array_slice($body, 0, $close));

        return array_values(array_filter($commands, static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#')));
    }
}
