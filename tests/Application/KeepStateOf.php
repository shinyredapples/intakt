<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class KeepStateOf implements CompilerPassInterface
{
    public function __construct(private string $serviceId)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition($this->serviceId)->clearTag('kernel.reset');
    }
}
