<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// FrameworkBundle registers the request collector only where the profiler is on (dev and test), so prod keeps no collector to swap; the class is swapped, not decorated, because the collector is also an event subscriber and the session's usage callback.
final class RedactRequestProfilesPass implements CompilerPassInterface
{
    private const string REQUEST_COLLECTOR = 'data_collector.request';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::REQUEST_COLLECTOR)) {
            return;
        }

        $container->getDefinition(self::REQUEST_COLLECTOR)->setClass(RedactedRequestDataCollector::class);
    }
}
