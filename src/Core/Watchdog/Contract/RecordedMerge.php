<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class RecordedMerge
{
    public string $head;
    public string $base;

    public function __construct(string $head, string $base)
    {
        $this->head = CommitName::full($head, 'recorded merge head');
        $this->base = CommitName::full($base, 'recorded merge base');
    }
}
