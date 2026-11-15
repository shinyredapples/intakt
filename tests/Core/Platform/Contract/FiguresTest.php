<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Platform\Contract\Figure;
use Intakt\Core\Platform\Contract\FigureKind;
use Intakt\Core\Platform\Contract\Figures;
use Intakt\Core\Platform\Contract\MixedFigureKinds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Figures::class)]
#[CoversClass(MixedFigureKinds::class)]
#[UsesClass(Figure::class)]
final class FiguresTest extends TestCase
{
    public function testAMeasuredFigureReadsWithTheWordMeasuredAfterIt(): void
    {
        self::assertSame('120 total measured', new Figures()->marked('120 total', FigureKind::Measured));
    }

    public function testACountedFigureReadsWithTheWordCountedAfterIt(): void
    {
        self::assertSame('round 2 counted', new Figures()->marked('round 2', FigureKind::Counted));
    }

    public function testAnEstimatedFigureReadsWithTheWordEstimatedAfterIt(): void
    {
        self::assertSame('0.0459 USD estimated', new Figures()->marked('0.0459 USD', FigureKind::Estimated));
    }

    public function testATotalOverFiguresOfOneKindIsAllowed(): void
    {
        $total = new Figures()->total(new Figure(100, FigureKind::Measured), new Figure(20, FigureKind::Measured));

        self::assertSame(120, $total->amount);
        self::assertSame(FigureKind::Measured, $total->kind);
    }

    public function testATotalOverMeasuredAndEstimatedFiguresIsRefused(): void
    {
        $this->expectException(MixedFigureKinds::class);

        new Figures()->total(new Figure(120, FigureKind::Measured), new Figure(0.0459, FigureKind::Estimated));
    }

    public function testATotalOverNoFiguresIsRefused(): void
    {
        $this->expectException(MixedFigureKinds::class);

        new Figures()->total();
    }
}
