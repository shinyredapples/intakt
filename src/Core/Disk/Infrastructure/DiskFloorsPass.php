<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Domain\FloorRefused;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// Checked while the container is built, because the floor service is made only when work asks, so a bad floor would otherwise boot unnoticed.
final readonly class DiskFloorsPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        foreach ([DiskServices::FREE_BYTES_FLOOR, DiskServices::FREE_INODES_FLOOR] as $parameter) {
            FloorRefused::unlessAtLeastOne($parameter, $container->getParameter($parameter));
        }
    }
}
