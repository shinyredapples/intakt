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
        if (1 !== preg_match('/^0::(\/.*)$/m', self::contentsOf(self::PROCESS_CGROUP), $cgroup)) {
            return '';
        }

        return self::contentsOf(rtrim(self::CGROUP_ROOT.$cgroup[1], '/').'/'.$name);
    }

    private static function contentsOf(string $file): string
    {
        return is_readable($file) ? (string) file_get_contents($file) : '';
    }
}
