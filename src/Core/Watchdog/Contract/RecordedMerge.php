<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use InvalidArgumentException;

final readonly class RecordedMerge
{
    private const string RANGE = '..';

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $base,
        public string $head,
    ) {
        WatchedMain::mustBeACommitName($base);
        WatchedMain::mustBeACommitName($head);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromRange(string $range): self
    {
        $parts = explode(self::RANGE, $range);

        if (2 !== count($parts)) {
            throw new InvalidArgumentException(sprintf('"%s" is no <base>..<head> range', $range));
        }

        return new self($parts[0], $parts[1]);
    }
}
