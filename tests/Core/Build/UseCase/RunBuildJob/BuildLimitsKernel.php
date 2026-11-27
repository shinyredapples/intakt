<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Kernel as IntaktKernel;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

// Intakt's configuration with the build limits set to values other than the shipped defaults, so a test can tell limits read from the install from limits written into the wiring.
final class BuildLimitsKernel extends Kernel
{
    use MicroKernelTrait {
        registerContainerConfiguration as private registerIntaktConfiguration;
    }

    public const int ALLOWANCE_TOKENS = 1_234;

    public const int IDLE_SECONDS = 56;

    public const int CEILING_TOKENS = 7_890;

    public const int TASK_TOTAL_TOKENS = 12_345;

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/build-limits';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerIntaktConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setParameter(BuildServices::ALLOWANCE_TOKENS, self::ALLOWANCE_TOKENS);
            $container->setParameter(BuildServices::IDLE_SECONDS, self::IDLE_SECONDS);
            $container->setParameter(BuildServices::CEILING_TOKENS, self::CEILING_TOKENS);
            $container->setParameter(BuildServices::TASK_TOTAL_TOKENS, self::TASK_TOTAL_TOKENS);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        IntaktKernel::addApplicationPasses($container);
    }
}
