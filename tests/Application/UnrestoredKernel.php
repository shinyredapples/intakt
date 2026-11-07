<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Kernel as IntaktKernel;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

// Intakt's kernel is final, so its build without the restore of the process settings has to stand beside it; it is what the "fails when the restore is removed" test runs.
final class UnrestoredKernel extends Kernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/unrestored';
    }

    protected function build(ContainerBuilder $container): void
    {
        IntaktKernel::addApplicationPasses($container);
    }
}
