<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support\Fixtures;

// Entries are found but fail when read, because that is the order of a process that ends between a check of its /proc entry and the read, which a real process cannot be made to hit on demand.
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

    // Returns false without a warning of its own, because PHP already warns when an open fails, as it does for a /proc entry that is gone.
    public function stream_open(): bool
    {
        return false;
    }
}
