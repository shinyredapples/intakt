<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// A saved profile is a file that outlives the request, and the app writes down no secret (the logging rule): only collectors that measure a request are registered, so a collector that could hold a credential stores nothing at all. Runs before FrameworkBundle's ProfilerPass, which reads the tags.
final class ProfilerCollectorAllowListPass implements CompilerPassInterface
{
    public const array COLLECTORS = ['db', 'messenger', 'time', 'memory'];

    private const string TAG = 'data_collector';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('profiler')) {
            return;
        }

        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $tags) {
            if (!$this->measuresARequest($tags)) {
                $container->getDefinition($serviceId)->clearTag(self::TAG);
            }
        }
    }

    /**
     * @param array<mixed> $tags
     */
    private function measuresARequest(array $tags): bool
    {
        $attributes = $tags[0] ?? null;

        return is_array($attributes) && in_array($attributes['id'] ?? null, self::COLLECTORS, true);
    }
}
