<?php

declare(strict_types=1);

namespace Intakt\Gates;

final class OwnCgroup
{
    private const string PROCESS_CGROUP = '/proc/self/cgroup';

    private const string CGROUP_ROOT = '/sys/fs/cgroup';

    // Because a cgroup v2 limit file sits under the `0::` path of this process; outside cgroup v2, or unreadable, it is empty.
    public static function file(string $name): string
    {
        return self::fileAndAncestors($name)[0] ?? '';
    }

    /**
     * Because a parent cgroup's limit binds this process as much as its own, the file of every cgroup from the own one up to the root counts.
     *
     * @return list<string>
     */
    public static function fileAndAncestors(string $name): array
    {
        if (1 !== preg_match('/^0::(\/.*)$/m', self::contentsOf(self::PROCESS_CGROUP), $cgroup)) {
            return [];
        }

        $files = [];

        for ($path = rtrim($cgroup[1], '/'); '' !== $path; $path = rtrim(dirname($path), '/')) {
            $files[] = self::contentsOf(self::CGROUP_ROOT.$path.'/'.$name);
        }

        return $files;
    }

    private static function contentsOf(string $file): string
    {
        return is_readable($file) ? (string) file_get_contents($file) : '';
    }
}
