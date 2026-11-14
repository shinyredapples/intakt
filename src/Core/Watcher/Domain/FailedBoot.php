<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

// Holds the reason a head did not boot while main booted, because the ledger line and the send-back show it; the reason arrives already redacted from BootResults.
final readonly class FailedBoot
{
    // Written as the cause of the head_red line, so a reader tells a head that does not boot from red CI.
    public const string CAUSE = 'boot_failed';

    public function __construct(public string $reason)
    {
    }
}
