<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// Names a trusted install by what pins its contents, so heads with the same lock inputs share one snapshot.
final readonly class DependencySnapshotKey
{
    private function __construct(public string $value)
    {
    }

    /**
     * @param array<string, string> $lockFiles the bytes of each lock input file, by its path
     */
    public static function ofLockInputs(array $lockFiles, string $platform): self
    {
        ksort($lockFiles, SORT_STRING);
        $parts = [$platform];

        foreach ($lockFiles as $path => $bytes) {
            $parts[] = (string) $path;
            $parts[] = $bytes;
        }

        // Length-prefixed, because a path or a file's bytes may hold any separator.
        return new self(hash('sha256', implode('', array_map(static fn (string $part): string => strlen($part).':'.$part, $parts))));
    }
}
