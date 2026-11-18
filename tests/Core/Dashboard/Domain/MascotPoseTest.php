<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\MascotPose;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MascotPose::class)]
final class MascotPoseTest extends TestCase
{
    private const string MASCOT = __DIR__.'/../../../../assets/images/mascot/';

    /**
     * @return iterable<string, array{MascotPose}>
     */
    public static function poses(): iterable
    {
        foreach (MascotPose::cases() as $pose) {
            yield $pose->value => [$pose];
        }
    }

    #[DataProvider('poses')]
    public function testEachPoseHasItsLightAndDarkImageOnDisk(MascotPose $pose): void
    {
        foreach (['light', 'dark'] as $theme) {
            self::assertFileExists(sprintf('%soctopus-%s-%s.webp', self::MASCOT, $pose->value, $theme));
        }
    }

    #[DataProvider('poses')]
    public function testEachPoseHasASquareImageOnDiskForEachSizeTheDashboardShowsAndItsDouble(MascotPose $pose): void
    {
        foreach (['light', 'dark'] as $theme) {
            foreach ([96, 144, 192, 288] as $px) {
                $file = sprintf('%soctopus-%s-%s-%d.webp', self::MASCOT, $pose->value, $theme, $px);
                self::assertFileExists($file);
                $size = getimagesize($file);
                self::assertNotFalse($size);
                self::assertSame([$px, $px], [$size[0], $size[1]]);
            }
        }
    }

    // Only the bowing pose has them, because only it is shown at 72 px, beside an approved newest round; 144 serves as its 2x.
    public function testTheBowingPoseHasASquare72PxImageOnDiskInEachTheme(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $file = sprintf('%soctopus-bowing-%s-72.webp', self::MASCOT, $theme);
            self::assertFileExists($file);
            $size = getimagesize($file);
            self::assertNotFalse($size);
            self::assertSame([72, 72], [$size[0], $size[1]]);
        }
    }

    public function testThePosesAreWaitingConductingBowingAndAsleep(): void
    {
        self::assertSame(['waiting', 'conducting', 'bowing', 'asleep'], array_column(MascotPose::cases(), 'value'));
    }
}
