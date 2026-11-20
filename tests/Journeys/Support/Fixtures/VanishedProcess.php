<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support\Fixtures;

// A process table whose entries are found but fail when read, the order of a process that ends between finding its entry and reading it.
final class VanishedProcess
{
    public const string SCHEME = 'vanished';

    /** @var resource|null */
    public $context;

    /**
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        return ['mode' => 0o100444, 'size' => 100];
    }

    // PHP itself warns when an open fails, as it does for a /proc entry that is gone.
    public function stream_open(): bool
    {
        return false;
    }
}
