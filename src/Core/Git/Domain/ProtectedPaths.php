<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

// The owner's list for one project, never a file of the repository, because a change could otherwise edit the list that judges it.
final readonly class ProtectedPaths
{
    // Because git reads the submodules from this file at the root only, and a submodule brings in code no diff of the repository shows.
    private const string SUBMODULES_FILE = '.gitmodules';

    // Relative, at most one trailing slash, and no empty, "." or ".." part, because git never prints such a path and the entry would protect nothing.
    private const string WELL_FORMED = '#\A(?!\.\.?(?:/|\z))(?!.*/\.\.?(?:/|\z))[^/]+(?:/[^/]+)*/?\z#s';

    /** @var list<string> */
    private array $entries;

    /**
     * @param list<string> $entries
     *
     * @throws ProtectedPathRefused
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            if (1 !== preg_match(self::WELL_FORMED, $entry)) {
                throw ProtectedPathRefused::entry($entry);
            }
        }

        $this->entries = array_map(static fn (string $entry): string => rtrim($entry, '/'), $entries);
    }

    /**
     * @param list<ChangedPath> $changes
     *
     * @return list<string>
     */
    public function touchedBy(array $changes): array
    {
        $touched = [];
        foreach ($changes as $change) {
            if ($this->covers($change)) {
                $touched[$change->path] = true;
            }
        }

        $paths = array_map(strval(...), array_keys($touched));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function covers(ChangedPath $change): bool
    {
        if ($change->submodule || self::SUBMODULES_FILE === $change->path) {
            return true;
        }

        return array_any($this->entries, static fn (string $entry): bool => $change->path === $entry || str_starts_with($change->path, $entry.'/'));
    }
}
