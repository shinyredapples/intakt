<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Read when the service is built, not when the container is compiled, so an image's cached container never carries the processors of the machine that built it.
final readonly class HostProcessors
{
    public function gatesAtOnce(#[Autowire(param: BuildServices::SLOTS)] int $slots): GatesAtOnce
    {
        return new GatesAtOnce($this->online(), $slots);
    }

    // At least one, because sysconf answers -1 when it cannot tell.
    private function online(): int
    {
        return max(1, posix_sysconf(POSIX_SC_NPROCESSORS_ONLN));
    }
}
