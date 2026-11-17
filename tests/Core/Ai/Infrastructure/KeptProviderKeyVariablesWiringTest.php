<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\KeptProviderKeyVariables;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionProperty;

#[CoversClass(KeptProviderKeyVariables::class)]
#[UsesClass(FixedEnvVars::class)]
final class KeptProviderKeyVariablesWiringTest extends KernelTestCase
{
    // Read through reflection because the loaders are private; the test container adds an empty env loader, because the framework drops its vault loader where no vault directory exists.
    public function testTheKeptKeyIsHandedEveryOtherEnvLoaderButItself(): void
    {
        $container = self::getContainer();
        $kept = $container->get(KeptProviderKeyVariables::class);
        self::assertInstanceOf(KeptProviderKeyVariables::class, $kept);

        $others = new ReflectionProperty(KeptProviderKeyVariables::class, 'otherLoaders')->getValue($kept);
        self::assertIsIterable($others);

        self::assertSame([$container->get('intakt.test.empty_env_var_loader')], [...$others]);
    }
}
