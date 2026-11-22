<?php

declare(strict_types=1);

namespace Intakt\Upstream\FrameworkDebugConfigStaleContainer;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

// reproduce.php passes the autoloader it was started with, so the folder also runs outside the checkout.
require getenv('PROBE_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';

// A kernel with FrameworkBundle only; its config file, cache and logs live in PROBE_DIR, so a run never touches the checkout.
final class ProbeKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle()];
    }

    public function getCacheDir(): string
    {
        return getenv('PROBE_DIR').'/cache';
    }

    public function getLogDir(): string
    {
        return getenv('PROBE_DIR').'/log';
    }

    public function getProjectDir(): string
    {
        return (string) getenv('PROBE_DIR');
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->import(getenv('PROBE_DIR').'/config/framework.php');
    }
}

// Debug decides whether Symfony checks the cached container for freshness, so it comes from APP_DEBUG, which reproduce.php sets.
(new Application(new ProbeKernel('dev', '1' === getenv('APP_DEBUG'))))->run();
