<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PhpStanIncludes
{
    public const string CONFIGURATION = 'config/gates/phpstan.neon';

    private const string INCLUDE_LINE = '/^\s+-\s+(?<path>[^\s#\'"]+)\s*$/';

    // Because the gate configuration and the installed packages are no lists: only what the analysis includes from the rest of the repository is.
    private const array NOT_LISTS = ['config/gates/', 'vendor/'];

    /**
     * @return list<string>|null the repository paths of the ratchet lists, null when an include is not a relative path inside the repository on its own line
     */
    public function ratchetListsIn(string $configuration): ?array
    {
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

        foreach (preg_split('/\R/', $configuration) ?: [] as $line) {
            if ('' === trim($line) || str_starts_with(ltrim($line), '#')) {
                continue;
            }

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
