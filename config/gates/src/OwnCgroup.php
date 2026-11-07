<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class OwnCgroup
{
    private const string PROCESS_CGROUP = '/proc/self/cgroup';

    private const string CGROUP_ROOT = '/sys/fs/cgroup';

    public function __construct(
        private string $processCgroup = self::PROCESS_CGROUP,
        private string $root = self::CGROUP_ROOT,
    ) {
    }

    // Because a cgroup v2 limit file sits under the `0::` path of this process; outside cgroup v2, or unreadable, it is empty.
    public function file(string $name): string
    {
        return $this->fileAndAncestors($name)[0] ?? '';
    }

    // Because a parent cgroup's limit binds this process as much as its own, the file of every cgroup from the own one up to the root counts.
    /**
     * @return list<string>
     */
    public function fileAndAncestors(string $name): array
    {
        if (1 !== preg_match('/^0::(\/.*)$/m', $this->contentsOf($this->processCgroup), $cgroup)) {
            return [];
        }

        $files = [];

        // Because in a private cgroup namespace the process sits at the root (`0::/`), whose limits are the container's own, the root is read too.
        for ($path = $cgroup[1]; '/' !== $path; $path = dirname($path)) {
            $files[] = $this->contentsOf($this->root.rtrim($path, '/').'/'.$name);
        }

        return [...$files, $this->contentsOf($this->root.'/'.$name)];
    }

    private function contentsOf(string $file): string
    {
        return is_readable($file) ? (string) file_get_contents($file) : '';
    }
}
