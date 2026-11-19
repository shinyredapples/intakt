<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use Intakt\Plugin\Gate\Contract\DependencyStep;

// The install command is hashed with the lock inputs, so a snapshot made by an older install command is never reused by a newer one.
final readonly class DependencySnapshotKey
{
    private function __construct(public string $value)
    {
    }

    // A missing file is hashed as absent, not as empty, because a tree without the file and a tree with an empty one are different lock inputs.
    /**
     * @param array<string, string|null> $contents
     */
    public static function of(DependencyStep $step, array $contents, string $platformVersion): self
    {
        $parts = [$step->install, $platformVersion];

        foreach ($step->lockFiles as $name) {
            $file = $contents[$name] ?? null;
            $parts[] = $name;
            $parts[] = null === $file ? 'absent' : 'present:'.$file;
        }

        return new self(hash('sha256', implode('', array_map(self::lengthPrefixed(...), $parts))));
    }

    public static function isValue(string $name): bool
    {
        return 1 === preg_match('/^[0-9a-f]{64}$/D', $name);
    }

    // Length-prefixed, because a separator could also appear inside a lock file and move the boundary between two parts.
    private static function lengthPrefixed(string $part): string
    {
        return strlen($part).':'.$part;
    }
}
