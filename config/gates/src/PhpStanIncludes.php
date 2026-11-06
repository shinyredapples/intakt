<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PhpStanIncludes
{
    public const string CONFIGURATION = 'config/gates/phpstan.neon';

    private const string INCLUDE_LINE = '/^\s+-\s+(?<path>[\w.\/-]+)\s*$/';

    private const string UNREADABLE_INCLUDES_KEY = '/^(?!includes:\h*\r?$)[\'"]?includes[\'"]?\h*:/m';

    // Because the gate configuration and the installed packages are no lists: only what the analysis includes from the rest of the repository is.
    private const array NOT_LISTS = ['config/gates/', 'vendor/'];

    // Because an include the gate cannot place could hide a list, anything but a block of plain relative paths inside the repository, one per "- path" line, yields null: a flow list, a comment after the key or a %placeholder%.
    /**
     * @return list<string>|null
     */
    public function ratchetListsIn(string $configuration): ?array
    {
        if (1 === preg_match(self::UNREADABLE_INCLUDES_KEY, $configuration)) {
            return null;
        }

        $paths = [];

        foreach ($this->includeLines($configuration) as $line) {
            $path = 1 === preg_match(self::INCLUDE_LINE, $line, $match) ? $this->repositoryPath($match['path']) : null;

            if (null === $path) {
                return null;
            }

            $paths[] = $path;
        }

        return array_values(array_filter($paths, $this->isList(...)));
    }

    /**
     * @return list<string>
     */
    private function includeLines(string $configuration): array
    {
        $lines = [];
        $inIncludes = false;

        foreach ($this->meaningfulLines($configuration) as $line) {
            if (ltrim($line) === $line) {
                $inIncludes = 'includes:' === rtrim($line);
                continue;
            }

            if ($inIncludes) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function meaningfulLines(string $configuration): array
    {
        $lines = preg_split('/\R/', $configuration);

        return false === $lines ? [] : array_values(array_filter($lines, static fn (string $line): bool => '' !== trim($line) && !str_starts_with(ltrim($line), '#')));
    }

    private function repositoryPath(string $include): ?string
    {
        if (str_starts_with($include, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', dirname(self::CONFIGURATION).'/'.$include) as $segment) {
            if ('..' === $segment && [] === $segments) {
                return null;
            }

            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return implode('/', $segments);
    }

    private function isList(string $path): bool
    {
        return !array_any(self::NOT_LISTS, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }
}
