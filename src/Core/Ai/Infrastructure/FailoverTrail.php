<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

// What one provider failover learned, kept by the call that made it because Symfony AI's FailoverPlatform reports neither who answered nor why it gave up; excluded from the container because one is built per call.
#[Exclude]
final class FailoverTrail
{
    private ?Throwable $hardFailure = null;
    private ?EveryAccountCoolingDown $firstOut = null;
    private ?Provider $answeredBy = null;

    public function halt(Throwable $failure): void
    {
        $this->hardFailure ??= $failure;
    }

    public function markOut(EveryAccountCoolingDown $out): void
    {
        $this->firstOut ??= $out;
    }

    public function firstOut(): ?EveryAccountCoolingDown
    {
        return $this->firstOut;
    }

    public function hardFailure(): ?Throwable
    {
        return $this->hardFailure;
    }

    public function answer(Provider $provider): void
    {
        $this->answeredBy = $provider;
    }

    public function answeredBy(): ?Provider
    {
        return $this->answeredBy;
    }
}
