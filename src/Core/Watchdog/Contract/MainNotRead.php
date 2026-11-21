<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use RuntimeException;

// A failed read, never an answer: main was not read, so the watchdog cannot call it clean and must not call it rewritten.
final class MainNotRead extends RuntimeException
{
    public static function missing(string $repository): self
    {
        return new self(sprintf('Main was not read, because %s is not an existing folder.', $repository));
    }

    // Because git would otherwise read the repository that encloses a plain folder and report its main as this one's.
    public static function notTheRepository(string $repository, string $found): self
    {
        return new self(sprintf('Main was not read, because %s is not the repository itself (git found %s).', $repository, '' === $found ? 'no repository top' : $found));
    }

    // Because a shallow history lacks the commits a force push would have dropped, so it cannot show a rewrite.
    public static function shallow(string $repository): self
    {
        return new self(sprintf('Main was not read, because %s is a shallow repository.', $repository));
    }

    // Because a walk that stops early (a repository cut shallow during the read) would hide the commits behind the stop from the watchdog.
    public static function brokenWalk(string $commit, string $tip): self
    {
        return new self(sprintf('Main was not read, because the first-parent walk from %s to %s is not an unbroken chain.', $commit, $tip));
    }

    public static function because(string $reason): self
    {
        return new self(sprintf('Main was not read, because %s.', $reason));
    }
}
