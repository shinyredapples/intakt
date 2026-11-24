<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Exact host names only, so an allowlist cannot be widened by a pattern, name an address directly or carry anything else into the proxy's config.
final readonly class JobEgress
{
    // Lowercase labels and an alphabetic top level, so an IP literal, a wildcard, a leading dot or a space never passes.
    private const string HOST = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D';

    private function __construct(public EgressHosts $hosts)
    {
    }

    // Meant for probe jobs and a check job whose gate names no registry, because they must get no network at all.
    public static function none(): self
    {
        return new self(new EgressHosts());
    }

    public static function to(EgressHosts $hosts): self
    {
        if ($hosts->isEmpty()) {
            throw JobRefused::forEmptyAllowlist();
        }

        foreach ($hosts as $host) {
            if (1 !== preg_match(self::HOST, $host)) {
                throw JobRefused::forEgressHost($host);
            }
        }

        return new self(new EgressHosts(...array_unique([...$hosts])));
    }

    public function isNone(): bool
    {
        return $this->hosts->isEmpty();
    }
}
