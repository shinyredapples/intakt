<?php

declare(strict_types=1);

namespace Intakt;

use Intakt\Core\Mcp\Infrastructure\AuthorizeToolCallsPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuthorizeToolCallsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }
}
