<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\KeptProviderKeyVariables;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\EnvVarProcessor;

#[CoversClass(KeptProviderKeyVariables::class)]
#[UsesClass(FixedEnvVars::class)]
final class KeptProviderKeyVariablesWiringTest extends KernelTestCase
{
    // Read through reflection because the loaders are private; the expected list comes from the env var processor, because the framework keeps its vault loader only while a config/secrets/ directory exists, which a parallel test can create while the container compiles.
    public function testTheKeptKeyIsHandedEveryOtherEnvLoaderButItself(): void
    {
        $container = self::getContainer();
        $kept = $container->get(KeptProviderKeyVariables::class);
        self::assertInstanceOf(KeptProviderKeyVariables::class, $kept);

        $others = new ReflectionProperty(KeptProviderKeyVariables::class, 'otherLoaders')->getValue($kept);
        self::assertIsIterable($others);

        $all = new ReflectionProperty(EnvVarProcessor::class, 'originalLoaders')->getValue($container->get('container.env_var_processor'));
        self::assertIsIterable($all);
        $expected = array_values(array_filter([...$all], static fn (mixed $loader): bool => $loader !== $kept));

        self::assertContains($container->get('intakt.test.empty_env_var_loader'), $expected);
        self::assertSame($expected, [...$others]);
    }
}
