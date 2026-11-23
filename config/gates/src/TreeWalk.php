<?php

declare(strict_types=1);

namespace Intakt\Gates;

use UnexpectedValueException;

final readonly class TreeWalk
{
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

        $target = realpath($path);

        if (false === $target) {
            throw new UnexpectedValueException('a link leads nowhere: '.$path);
        }

        yield $key."\0" => $this->settledStatusOf($target);

        for ($folder = dirname($target); str_starts_with($folder, $root.'/'); $folder = dirname($folder)) {
            // Because the key of the folder a link leads to ends in a zero byte, a relative path after it never passes for that key nor for a path in the tree.
            yield $key."\0".substr($folder, strlen($root) + 1) => $this->settledStatusOf($folder);
        }
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
