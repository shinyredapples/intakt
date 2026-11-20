<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// An allow-list instead of masking, because a collector that is never registered stores nothing, so a new kind of credential needs no new rule (docs/architecture/development-diagnostics.md).
final class ProfileOnlyRequestMeasuresPass implements CompilerPassInterface
{
    public const array MEASURES = ['db', 'messenger', 'time', 'memory'];

    private const string TAG = 'data_collector';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('profiler')) {
            return;
        }

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $this->keepOnlyAMeasure($container, $id, $tags);
        }
    }

    /**
     * @param array<array<string, mixed>> $tags
     */
    private function keepOnlyAMeasure(ContainerBuilder $container, string $id, array $tags): void
    {
        if (in_array($tags[0]['id'] ?? null, self::MEASURES, true)) {
            return;
        }

        $container->getDefinition($id)->clearTag(self::TAG);
    }
}
