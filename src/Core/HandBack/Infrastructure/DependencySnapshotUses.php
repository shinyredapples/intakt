<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use UnexpectedValueException;

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

        $order = json_decode($this->filesystem->readFile($file), flags: JSON_THROW_ON_ERROR);
        $names = [];

        foreach (is_array($order) && array_is_list($order) ? $order : [null] as $name) {
            if (!is_string($name)) {
                throw new UnexpectedValueException(sprintf('the use order %s is not a list of snapshot names', self::FILE));
            }

            $names[] = $name;
        }

        return $names;
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
}
