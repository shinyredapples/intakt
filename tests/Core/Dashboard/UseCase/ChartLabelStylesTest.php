<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// The sheet is read as text, as TrendsTileStylesTest does, because the size a browser draws needs no page to be seen in a rule.
#[CoversNothing]
final class ChartLabelStylesTest extends TestCase
{
    public function testOnAPhoneChartLabelsAreDrawnLargerThanTheFloorSoTheShrunkDrawingStaysAtFifteenPixels(): void
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);
        preg_match_all('/@media \(max-width: 760px\) \{(.*?)\n\}/s', $sheet, $blocks);

        self::assertMatchesRegularExpression('/\.c-axis, \.c-label \{ font-size: calc\(var\(--t15\) \* 1\.07\); \}/', implode("\n", $blocks[1]));
    }

    public function testOutsideAPhoneChartLabelsKeepTheirTokenSize(): void
    {
        $sheet = file_get_contents(__DIR__.'/../../../../assets/styles/components.css');
        self::assertIsString($sheet);

        self::assertMatchesRegularExpression('/^\.c-label \{[^}]*font: 600 var\(--t13\)/m', $sheet);
        self::assertMatchesRegularExpression('/^\.c-axis \{[^}]*font: 400 var\(--t13\)/m', $sheet);
    }
}
