<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

interface Probe
{
    public function answers(): bool;
}
