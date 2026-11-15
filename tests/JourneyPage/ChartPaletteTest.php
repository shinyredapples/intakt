<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use Iterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

// Color-vision deficiency is simulated with the full-severity matrices of Machado, Oliveira and Fernandes (2009) and measured in OKLab.
#[CoversClass(ChartPalette::class)]
#[UsesClass(JourneyInputFailed::class)]
final class ChartPaletteTest extends TestCase
{
    private const float LEAST_DISTANCE = 8.0;

    /**
     * @param list<list<float>> $vision
     */
    #[DataProvider('visions')]
    public function testEveryTwoSeriesColorsStayApartForEachKindOfVision(array $vision): void
    {
        $distances = [];

        foreach (ChartPalette::SERIES as $first => $one) {
            foreach (array_slice(ChartPalette::SERIES, $first + 1) as $other) {
                $distances[$one.' and '.$other] = round($this->distance($this->seen($one, $vision), $this->seen($other, $vision)), 1);
            }
        }

        self::assertSame([], array_filter($distances, static fn (float $distance): bool => $distance < self::LEAST_DISTANCE));
    }

    /**
     * @return Iterator<string, array{list<list<float>>}>
     */
    public static function visions(): Iterator
    {
        yield 'typical' => [[[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]]];
        yield 'protanopia' => [[[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]]];
        yield 'deuteranopia' => [[[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]]];
        yield 'tritanopia' => [[[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.303900]]];
    }

    public function testTheSeriesColorsAreGivenInTheirFixedOrder(): void
    {
        self::assertSame(['#2a78d6', '#eb6834', '#1baf7a', '#eda100'], array_map(ChartPalette::color(...), [0, 1, 2, 3]));
    }

    public function testASeriesBeyondTheFixedOrderIsRefusedInsteadOfCycled(): void
    {
        $this->expectException(JourneyInputFailed::class);
        $this->expectExceptionMessageMatches('/more series than the 4 colors/');

        ChartPalette::color(4);
    }

    /**
     * @param list<list<float>> $vision
     *
     * @return list<float>
     */
    private function seen(string $hex, array $vision): array
    {
        $linear = array_map(static function (int $offset) use ($hex): float {
            $channel = hexdec(substr($hex, $offset, 2)) / 255;

            return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);
        $simulated = array_map(static fn (array $row): float => max(0.0, min(1.0, $row[0] * $linear[0] + $row[1] * $linear[1] + $row[2] * $linear[2])), $vision);

        return $this->oklab($simulated);
    }

    /**
     * @param list<float> $rgb
     *
     * @return list<float>
     */
    private function oklab(array $rgb): array
    {
        [$red, $green, $blue] = $rgb;
        $long = (0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue) ** (1 / 3);
        $medium = (0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue) ** (1 / 3);
        $short = (0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue) ** (1 / 3);

        return [
            0.2104542553 * $long + 0.7936177850 * $medium - 0.0040720468 * $short,
            1.9779984951 * $long - 2.4285922050 * $medium + 0.4505937099 * $short,
            0.0259040371 * $long + 0.7827717662 * $medium - 0.8086757660 * $short,
        ];
    }

    /**
     * @param list<float> $one
     * @param list<float> $other
     */
    private function distance(array $one, array $other): float
    {
        return 100 * sqrt(($one[0] - $other[0]) ** 2 + ($one[1] - $other[1]) ** 2 + ($one[2] - $other[2]) ** 2);
    }
}
