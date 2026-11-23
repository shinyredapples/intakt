<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Generator;
use UnexpectedValueException;

final readonly class TreeWalk
{
    // Because Linux stops following links after 40 on one path, a route with more leads nowhere for the gates either.
    private const int MOST_LINKS_ON_A_ROUTE = 40;

    /**
     * @param list<string> $skipped
     */
    public function __construct(
        private array $skipped,
        private ?int $settledBefore,
    ) {
    }

    /**
     * @param list<string> $entered
     *
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    // Because a folder counts with its own times and inode, a file that came and went, or a folder moved away and back while the gates ran, shows even when every file is as it was; $entered names the real folders the walk is inside of.
    public function entries(string $path, string $key, array $entered): iterable
    {
        $status = $this->settledStatusOf($path);

        if (is_link($path)) {
            yield $key => $status.' '.$this->targetOf($path);
            yield from $this->entriesBehind($path, $key, $entered);

            return;
        }

        if (is_dir($path)) {
            yield $key => $status;
            yield from $this->entriesIn($path, $key, $entered);

            return;
        }

        yield $key => $status.' '.$this->bytesOf($path);
    }

    /**
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    // Because a folder the walk skips still tells by its own times and inode that it was moved away and back, it counts without what it holds; behind a link, the folder it leads to tells that, not the link, and so do the folders above it within $root, since moving one of them away also takes the gates' input away.
    public function folderAlone(string $path, string $key, string $root): iterable
    {
        yield $key => $this->settledStatusOf($path);

        if (!is_link($path)) {
            return;
        }

        $target = yield from $this->route($path, $key, $root);

        yield $key."\0" => $this->settledStatusOf($target);

        for ($folder = dirname($target); str_starts_with($folder, $root.'/'); $folder = dirname($folder)) {
            // Because the key of the folder a link leads to ends in a zero byte, a relative path after it never passes for that key nor for a path in the tree.
            yield $key."\0".substr($folder, strlen($root) + 1) => $this->settledStatusOf($folder);
        }
    }

    /**
     * @return Generator<string, string, mixed, string>
     *
     * @throws UnexpectedValueException
     */
    // Because realpath() hides the links part way along a route (a link to a folder behind another link), the route is followed one name at a time, and every folder and link it passes within $root counts, since moving one of them away also takes the gates' input away.
    private function route(string $link, string $key, string $root): Generator
    {
        $folder = realpath(dirname($link));

        if (false === $folder) {
            throw new UnexpectedValueException('cannot read '.dirname($link));
        }

        $target = $this->targetOf($link);
        $folder = str_starts_with($target, '/') ? '' : $folder;
        $names = $this->namesOf($target);
        $links = 1;

        while ([] !== $names) {
            $name = array_shift($names);

            if ('..' === $name) {
                $folder = rtrim(dirname('' === $folder ? '/' : $folder), '/');

                continue;
            }

            $next = $folder.'/'.$name;

            if (str_starts_with($next, $root.'/')) {
                // Because the key of the folder a link leads to ends in one zero byte, a key with two before its path never passes for that key, for a folder above it, nor for a path in the tree.
                yield $key."\0\0".substr($next, strlen($root) + 1) => $this->settledStatusOf($next);
            }

            if (!is_link($next)) {
                $folder = $next;

                continue;
            }

            if (++$links > self::MOST_LINKS_ON_A_ROUTE) {
                throw new UnexpectedValueException('a link leads back into itself: '.$link);
            }

            $target = $this->targetOf($next);
            $folder = str_starts_with($target, '/') ? '' : $folder;
            $names = [...$this->namesOf($target), ...$names];
        }

        $end = '' === $folder ? '/' : $folder;

        if (!file_exists($end)) {
            throw new UnexpectedValueException('a link leads nowhere: '.$link);
        }

        return $end;
    }

    /**
     * @throws UnexpectedValueException
     */
    // Because a change within the second a run started leaves no trace in the times, anything changed at or after that second gives no fingerprint.
    private function settledStatusOf(string $path): string
    {
        $status = @lstat($path);

        if (false === $status) {
            throw new UnexpectedValueException('cannot read '.$path);
        }

        if (null !== $this->settledBefore && $status['ctime'] >= $this->settledBefore) {
            throw new UnexpectedValueException('changed in the second the check started: '.$path);
        }

        return implode(' ', [$status['mode'], $status['size'], $status['mtime'], $status['ctime'], $status['ino']]);
    }

    /**
     * @return list<string>
     */
    private function namesOf(string $target): array
    {
        return array_values(array_filter(explode('/', $target), static fn (string $name): bool => '' !== $name && '.' !== $name));
    }

    /**
     * @throws UnexpectedValueException
     */
    private function targetOf(string $link): string
    {
        $target = @readlink($link);

        if (false === $target) {
            throw new UnexpectedValueException('cannot read '.$link);
        }

        return $target;
    }

    /**
     * @param list<string> $entered
     *
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    // Because the gates open a path through a link (a vendor/ that points at a shared install, a tracked path whose folder became a link), what is behind the link counts and not only where it points.
    private function entriesBehind(string $link, string $key, array $entered): iterable
    {
        if (!file_exists($link)) {
            return;
        }

        $target = realpath($link);

        if (false === $target || in_array($target, $entered, true)) {
            throw new UnexpectedValueException('a link leads nowhere or back into its own folder: '.$link);
        }

        // Because no file name holds a zero byte, the key of what is behind a link never passes for a path beside it, nor for a skipped one.
        yield from $this->entries($target, $key."\0", [...$entered, $target]);
    }

    /**
     * @param list<string> $entered
     *
     * @return iterable<string, string>
     *
     * @throws UnexpectedValueException
     */
    private function entriesIn(string $folder, string $key, array $entered): iterable
    {
        $names = @scandir($folder);

        if (false === $names) {
            throw new UnexpectedValueException('cannot read '.$folder);
        }

        foreach (array_diff($names, ['.', '..']) as $name) {
            $child = '' === $key ? $name : $key.'/'.$name;

            if (!in_array($child, $this->skipped, true)) {
                yield from $this->entries($folder.'/'.$name, $child, $entered);
            }
        }
    }

    /**
     * @throws UnexpectedValueException
     */
    private function bytesOf(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $hash = @hash_file('sha256', $path);

        if (false === $hash) {
            throw new UnexpectedValueException('cannot read '.$path);
        }

        return $hash;
    }
}
