<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Kernel as IntaktKernel;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

// Intakt's configuration with an idle time of YAML true, which a typed parameter would read as one second.
final class RefusedBuildLimitsKernel extends Kernel
{
    use MicroKernelTrait {
        registerContainerConfiguration as private registerIntaktConfiguration;
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/refused-build-limits';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerIntaktConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->setParameter(BuildServices::IDLE_SECONDS, true);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        IntaktKernel::addApplicationPasses($container);
    }
}
