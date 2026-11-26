<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk\Infrastructure;

use Intakt\Core\Disk\Infrastructure\DiskConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(DiskConfiguration::class)]
final class DiskConfigurationTest extends TestCase
{
    public function testMissingDiskSettingsFallBackToTheDefaults(): void
    {
        $settings = $this->processed([]);

        self::assertSame(['min_free_bytes' => DiskConfiguration::DEFAULT_MIN_FREE_BYTES, 'min_free_inodes' => DiskConfiguration::DEFAULT_MIN_FREE_INODES], $settings['disk']);
    }

    public function testFloorsOfOneAreAccepted(): void
    {
        $settings = $this->processed(['disk' => ['min_free_bytes' => 1, 'min_free_inodes' => 1]]);

        self::assertSame(['min_free_bytes' => 1, 'min_free_inodes' => 1], $settings['disk']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function refusedFloors(): iterable
    {
        yield 'zero bytes' => ['min_free_bytes', 0];
        yield 'negative inodes' => ['min_free_inodes', -1];
        yield 'bytes written with a unit' => ['min_free_bytes', '2G'];
        yield 'fractional inodes' => ['min_free_inodes', 1.5];
    }

    #[DataProvider('refusedFloors')]
    public function testAFloorThatIsNotAPositiveWholeNumberIsRefusedAtBootNamingTheKey(string $key, mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('intakt.disk.'.$key);

        $this->processed(['disk' => [$key => $value]]);
    }

    public function testAnUnknownDiskSettingIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processed(['disk' => ['min_free_percent' => 5]]);
    }

    /**
     * @param array<string, mixed> $disk
     *
     * @return array{disk: array{min_free_bytes: int, min_free_inodes: int}}
     */
    private function processed(array $disk): array
    {
        /** @var array{disk: array{min_free_bytes: int, min_free_inodes: int}} */
        return new Processor()->processConfiguration(new DiskConfiguration(), [$disk]);
    }
}
