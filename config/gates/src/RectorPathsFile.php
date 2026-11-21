<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

// Rector hands its command-line paths to each parallel worker inside one shell command string, which Linux caps at 128 KiB, so a full run failed to start its workers ("Argument list too long"); a file named by the environment carries any number of paths.
final readonly class RectorPathsFile
{
    public const string VARIABLE = 'INTAKT_RECTOR_PATHS_FILE';

    /**
     * @param list<string> $paths
     */
    public static function written(array $paths): string
    {
        $contents = self::encoded($paths);
        $folder = self::folder();
        $file = $folder.'/'.hash('xxh128', $contents).'.json';

        try {
            new Filesystem()->mkdir($folder);
        } catch (IOException $failure) {
            throw new ScopeFailure('cannot write the rector paths file: '.$failure->getMessage(), 0, $failure);
        }

        // The list goes to a temporary file in the same folder and rename() replaces the final directory entry, so the final name never holds a partly written list and a symlink there is replaced, never followed.
        $temporary = tempnam($folder, 'paths-');

        if (false === $temporary || strlen($contents) !== file_put_contents($temporary, $contents) || !rename($temporary, $file)) {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            throw new ScopeFailure('cannot write the rector paths file '.$file);
        }

        return $file;
    }

    /**
     * @return list<string>
     */
    public static function read(string $file): array
    {
        $contents = is_file($file) && is_readable($file) ? file_get_contents($file) : false;

        if (false === $contents) {
            throw new ScopeFailure('the rector paths file '.$file.' is missing or unreadable');
        }

        $paths = self::decoded($file, $contents);

        if (!is_array($paths) || [] === $paths || !array_is_list($paths)) {
            throw new ScopeFailure('the rector paths file '.$file.' holds no list of paths');
        }

        return array_map(static fn (mixed $path): string => is_string($path) && '' !== $path ? $path : throw new ScopeFailure('the rector paths file '.$file.' holds no list of paths'), $paths);
    }

    private static function decoded(string $file, string $contents): mixed
    {
        try {
            // Decoded without associative arrays, so a JSON object such as {"0": "src/A.php"} stays an object and fails the list check.
            return json_decode($contents, false, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new ScopeFailure('the rector paths file '.$file.' does not decode: '.$failure->getMessage(), 0, $failure);
        }
    }

    /**
     * @param list<string> $paths
     */
    private static function encoded(array $paths): string
    {
        try {
            return json_encode($paths, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $failure) {
            throw new ScopeFailure('cannot encode the rector paths: '.$failure->getMessage(), 0, $failure);
        }
    }

    // Because Rector runs in the checked root, which --root can put elsewhere, a relative gates cache is resolved against bin/check's own directory here.
    private static function folder(): string
    {
        $directory = getcwd();

        if (false === $directory) {
            throw new ScopeFailure('cannot read the working directory');
        }

        return Path::makeAbsolute(GatesCache::directory(), $directory).'/rector-paths';
    }
}
