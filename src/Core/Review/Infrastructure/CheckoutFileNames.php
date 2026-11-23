<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

// The checkout's files by name, so a name cited without its directory (`B.php:12`) can be matched to every file that carries it, wherever it is.
final readonly class CheckoutFileNames
{
    private const string GIT_DIRECTORY = '.git';

    /**
     * @param array<string, list<string>> $pathsByName
     * @param array<string, true>         $entryNames
     */
    private function __construct(private array $pathsByName, private array $entryNames)
    {
    }

    // Null when a directory cannot be listed, because a file in it may carry the name and would then be missed.
    public static function in(string $root): ?self
    {
        $pathsByName = [];
        $entryNames = [];

        try {
            $entries = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $entry): bool => self::GIT_DIRECTORY !== $entry->getFilename(),
            ), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($entries as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }

                $entryNames[$entry->getFilename()] = true;

                if ($entry->isFile()) {
                    $pathsByName[$entry->getFilename()][] = ltrim(substr($entry->getPathname(), strlen($root)), '/');
                }
            }
        } catch (UnexpectedValueException) {
            return null;
        }

        return new self($pathsByName, $entryNames);
    }

    /**
     * @return list<string>
     */
    public function namesOfFilesAndDirectories(): array
    {
        return array_map(strval(...), array_keys($this->entryNames));
    }

    /**
     * @return list<string>
     */
    public function namesOfFiles(): array
    {
        return array_map(strval(...), array_keys($this->pathsByName));
    }

    /**
     * @return list<string>
     */
    public function pathsNamed(string $name): array
    {
        return $this->pathsByName[$name] ?? [];
    }
}
