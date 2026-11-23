<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Kernel as IntaktKernel;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

// Intakt's configuration with the tool time limit set to a value other than the shipped default, so a test can tell a setting that is read from the install from one that is written into the wiring.
final class ToolTimeLimitKernel extends Kernel
{
    use MicroKernelTrait {
        registerContainerConfiguration as private registerIntaktConfiguration;
    }

    public const int LIMIT = 1800;

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/tool-time-limit';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerIntaktConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setParameter(BuildServices::TOOL_TIME_LIMIT, self::LIMIT);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        IntaktKernel::addApplicationPasses($container);
    }
}
