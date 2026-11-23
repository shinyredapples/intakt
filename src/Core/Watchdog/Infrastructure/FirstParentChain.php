<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Watchdog\Contract\MainNotRead;

// The walk proves itself from its own output, because a repository cut shallow after the ancestry check makes git end the walk early without an error, and a check before or after the walk cannot see a cut in between.
final readonly class FirstParentChain
{
    /**
     * @param list<string> $commits
     */
    private function __construct(
        public array $commits,
        // The first parent of the oldest listed commit, because the caller still has to prove that the chain stands on the commit the walk started from.
        public ?string $foot,
    ) {
    }

    /**
     * @param list<string> $walk
     *
     * @throws MainNotRead
     */
    public static function fromWalk(array $walk, string $since, string $tip): self
    {
        $commits = [];
        $foot = $last = null;
        foreach ($walk as $line) {
            $ids = explode(' ', $line);
            // A commit without a parent ends the chain, because a shallow cut makes git list the cut commit as parentless.
            $firstParent = $ids[1] ?? throw MainNotRead::brokenWalk($since, $tip);
            if (null !== $last && $firstParent !== $last) {
                throw MainNotRead::brokenWalk($since, $tip);
            }

            $foot ??= $firstParent;
            $commits[] = $last = $ids[0];
        }

        return ($last ?? $since) === $tip ? new self($commits, $foot) : throw MainNotRead::brokenWalk($since, $tip);
    }
}
