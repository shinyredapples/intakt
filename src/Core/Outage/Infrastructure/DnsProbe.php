<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

// Through the system resolver, so its own timeout and attempts bound the lookup; PHP's lookup functions take no timeout.
final readonly class DnsProbe implements Probe
{
    public function __construct(
        public string $host,
    ) {
    }

    public function answers(): bool
    {
        return false !== gethostbynamel($this->host);
    }
}
