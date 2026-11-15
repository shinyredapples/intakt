<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// ChartPaletteTest checks this order for color-blind separation, so a series color is never generated or cycled.
final readonly class ChartPalette
{
    public const array SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100'];
    public const string SURFACE = '#fcfcfb';
    public const string GRID = '#e6e5e1';
    public const string MUTED = '#52514e';
    public const string INK = '#0b0b0b';

    public static function color(int $series): string
    {
        return self::SERIES[$series] ?? throw new JourneyInputFailed('a chart has more series than the '.count(self::SERIES).' colors of its fixed order');
    }
}
