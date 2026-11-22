<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use FilesystemIterator;
use JsonException;
use SplFileInfo;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// An order of use rather than times, because the least recently used bound needs only which snapshot was used last.
final readonly class DependencySnapshotUses
{
    public const string FILE = '.uses';

    public function __construct(private Filesystem $filesystem)
    {
    }

    /**
     * @return list<string>
     */
    public function leastRecentFirstIn(string $store): array
    {
        $file = $store.'/'.self::FILE;

        if (!is_file($file)) {
            return [];
        }

        // The order only steers which snapshot goes first, so a damaged file or one that is no list of names counts as nothing recorded.
        try {
            $order = json_decode($this->filesystem->readFile($file), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($order) || !array_is_list($order) || $order !== array_filter($order, is_string(...))) {
            return [];
        }

        return $order;
    }

    /**
     * @param list<string> $leastRecentFirst
     */
    public function record(string $store, array $leastRecentFirst): void
    {
        // Written beside it and renamed over it, because a reader must never see half a list.
        $written = $store.'/'.self::FILE.bin2hex(random_bytes(8));
        $this->filesystem->dumpFile($written, json_encode($leastRecentFirst, JSON_THROW_ON_ERROR));

        if (!@rename($written, $store.'/'.self::FILE)) {
            throw new IOException(sprintf('the use order %s could not be replaced', self::FILE), path: $written);
        }
    }

    // Needed because a crash before a rename leaves the file written beside the use order, since Filesystem::dumpFile() and record() both write one first.
    public function removeTheLeftoversIn(string $store): void
    {
        foreach (new FilesystemIterator($store, FilesystemIterator::SKIP_DOTS) as $entry) {
            assert($entry instanceof SplFileInfo);
            $name = $entry->getFilename();

            if (self::FILE !== $name && str_starts_with($name, self::FILE) && $entry->isFile() && !$entry->isLink()) {
                $this->filesystem->remove($entry->getPathname());
            }
        }
    }
}
