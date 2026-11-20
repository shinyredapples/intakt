<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// FrameworkBundle defines the profiler only where it is on (dev and test), so prod keeps nothing to swap; the class is swapped, not decorated, because the profiler listener and the test client ask for a Profiler.
final class RedactRequestProfilesPass implements CompilerPassInterface
{
    // The lazy one is the proxy the profiler listener holds, and a proxy must be of its real instance's class.
    private const array PROFILERS = ['profiler', '.lazy_profiler'];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::PROFILERS as $profiler) {
            if ($container->hasDefinition($profiler)) {
                $container->getDefinition($profiler)->setClass(RedactingProfiler::class);
            }
        }
    }
}
