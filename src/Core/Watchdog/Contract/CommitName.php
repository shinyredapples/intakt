<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use InvalidArgumentException;

final readonly class CommitName
{
    // Both object formats, because a name that starts with a dash or holds a range would reach git as an option or a revision expression.
    private const string FULL = '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';

    /**
     * @throws InvalidArgumentException
     */
    public static function must(string $name): string
    {
        return 1 === preg_match(self::FULL, $name) ? $name : throw new InvalidArgumentException('A commit is named by its full hexadecimal name.');
    }
}
