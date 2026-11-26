<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Contract\DiskServices;
use Intakt\Core\Disk\Domain\FloorRefused;
use Intakt\Core\Disk\Infrastructure\DiskFloorsPass;
use Intakt\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DiskFloorsPass::class)]
#[CoversClass(FloorRefused::class)]
#[UsesClass(Kernel::class)]
final class DiskFloorsPassTest extends TestCase
{
    #[DataProvider('refusedFloors')]
    public function testTheContainerIsNotBuiltWithAFloorThatIsNotAWholeNumberOfAtLeastOne(string $parameter, mixed $value, string $named): void
    {
        $container = $this->containerWithFloors(1073741824, 10000);
        $container->setParameter($parameter, $value);

        $this->expectExceptionObject(new FloorRefused($parameter.' must be a whole number of at least 1, '.$named.' given'));

        $container->compile();
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function refusedFloors(): iterable
    {
        yield 'zero inodes' => [DiskServices::FREE_INODES_FLOOR, 0, '0'];
        yield 'negative bytes' => [DiskServices::FREE_BYTES_FLOOR, -1, '-1'];
        yield 'a number as text' => [DiskServices::FREE_BYTES_FLOOR, '1000', 'string'];
        yield 'a fraction' => [DiskServices::FREE_INODES_FLOOR, 1.5, 'float'];
        yield 'nothing' => [DiskServices::FREE_INODES_FLOOR, null, 'null'];
    }

    public function testTheContainerIsBuiltWithFloorsOfOne(): void
    {
        $container = $this->containerWithFloors(1, 1);

        $container->compile();

        self::assertSame(1, $container->getParameter(DiskServices::FREE_INODES_FLOOR));
    }

    // Through the kernel's own list of passes, so the test fails when the pass is not registered.
    private function containerWithFloors(int $bytes, int $inodes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(DiskServices::FREE_BYTES_FLOOR, $bytes);
        $container->setParameter(DiskServices::FREE_INODES_FLOOR, $inodes);
        Kernel::addApplicationPasses($container);

        return $container;
    }
}
