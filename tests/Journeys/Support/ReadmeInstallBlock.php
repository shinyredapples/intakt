<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

// The rule: the install block is the first code fence after the line that starts with `<!-- install-check`, so the README stays the one source of the commands.
final readonly class ReadmeInstallBlock
{
    private const string MARKER = '<!-- install-check';

    /**
     * @return list<string> the commands, one per line, comments and blank lines left out
     */
    public static function commandsIn(string $readme): array
    {
        $lines = explode("\n", str_replace("\r", '', $readme));
        $marker = array_find_key($lines, static fn (string $line): bool => str_starts_with($line, self::MARKER));

        if (null === $marker) {
            throw new InstallCheckCouldNotRun('the README has no line starting with "'.self::MARKER.'" above its install block');
        }

        $rest = array_slice($lines, $marker + 1);
        $open = array_find_key($rest, static fn (string $line): bool => '' !== trim($line));

        if (null === $open || !str_starts_with($rest[$open], '```')) {
            throw new InstallCheckCouldNotRun('the README marker is not followed by a code block');
        }

        $commands = [];

        foreach (array_slice($rest, $open + 1) as $line) {
            if (str_starts_with($line, '```')) {
                return [] === $commands ? throw new InstallCheckCouldNotRun('the README install block is empty') : $commands;
            }

            if ('' !== trim($line) && !str_starts_with(trim($line), '#')) {
                $commands[] = trim($line);
            }
        }

        throw new InstallCheckCouldNotRun('the README install block is not closed');
    }
}
