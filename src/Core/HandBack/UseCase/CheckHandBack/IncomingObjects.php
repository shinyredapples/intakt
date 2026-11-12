<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// A quarantine as git's own receive-pack keeps one: the bundle's objects stay apart until every check passed, so a refused bundle leaves nothing in the repository that a later bundle could name without shipping it.
final readonly class IncomingObjects
{
    private const string PREFIX = '/incoming-';

    /**
     * @throws HandBackNotChecked
     */
    public function open(string $repository): string
    {
        $objects = $repository.'/objects';

        if (!is_dir($objects)) {
            throw HandBackNotChecked::in($repository, 'it has no objects folder');
        }

        $folder = $objects.self::PREFIX.bin2hex(random_bytes(8));

        try {
            new Filesystem()->mkdir($folder, 0o700);
        } catch (IOException) {
            throw HandBackNotChecked::in($repository, 'its objects folder takes no quarantine');
        }

        return $folder;
    }

    /**
     * The objects the bundle's pack holds itself, because unbundling a thin pack appends the objects its deltas build on, from anywhere in the repository.
     *
     * @return array<string, int>
     *
     * @throws HandBackNotChecked
     */
    public function shipped(GivenHistory $history, string $folder, int $count): array
    {
        $indexes = glob($folder.'/pack/*.idx') ?: [];

        if ([] === $indexes) {
            return [];
        }

        $index = 1 === count($indexes) ? file_get_contents($indexes[0]) : false;
        $listing = false === $index ? null : $history->git->answerWithInput($history->repository, ['show-index'], $index);

        if (null === $listing) {
            throw HandBackNotChecked::in($history->repository, 'the unbundled pack could not be listed');
        }

        $offsets = [];
        foreach ('' === $listing ? [] : explode("\n", $listing) as $line) {
            [$offset, $object] = explode(' ', $line, 3) + [1 => ''];
            $offsets[$object] = (int) $offset;
        }

        // The appended objects come last in the pack, after the count its header states.
        asort($offsets, SORT_NUMERIC);

        return array_flip(array_slice(array_keys($offsets), 0, $count));
    }

    /**
     * @throws HandBackNotChecked
     */
    public function keep(string $folder): void
    {
        $objects = dirname($folder);
        $files = [...(glob($folder.'/[0-9a-f][0-9a-f]/*') ?: []), ...(glob($folder.'/pack/*') ?: [])];
        // An index last, because git finds a pack through its index, which must never name a pack file not there yet.
        usort($files, static fn (string $one, string $other): int => str_ends_with($one, '.idx') <=> str_ends_with($other, '.idx'));
        $filesystem = new Filesystem();

        try {
            foreach ($files as $file) {
                $target = $objects.substr($file, strlen($folder));

                // A file of the same name already there holds the same objects, because git names an object and a pack by their content.
                if (!file_exists($target)) {
                    $filesystem->mkdir(dirname($target));
                    $filesystem->rename($file, $target);
                }
            }
        } catch (IOException) {
            throw HandBackNotChecked::in(dirname($objects), 'the checked objects could not be moved into it');
        }
    }

    /**
     * @throws HandBackNotChecked
     */
    public function drop(string $folder): void
    {
        try {
            new Filesystem()->remove($folder);
        } catch (IOException) {
            throw HandBackNotChecked::in(dirname($folder, 2), 'its quarantine could not be removed');
        }
    }
}
