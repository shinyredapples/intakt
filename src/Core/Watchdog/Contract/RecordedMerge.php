<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

use InvalidArgumentException;

final readonly class RecordedMerge
{
    public string $head;
    public string $base;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $head, string $base)
    {
        $this->head = CommitName::must($head);
        $this->base = CommitName::must($base);
    }
}
