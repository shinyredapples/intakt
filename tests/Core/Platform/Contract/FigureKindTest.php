<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Platform\Contract\FigureKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FigureKind::class)]
final class FigureKindTest extends TestCase
{
    public function testAMeasuredFigureNamesTheProvidersUsageAnswerAsItsSource(): void
    {
        self::assertSame("Measured: read from the provider's usage answer.", FigureKind::Measured->source());
    }

    public function testACountedFigureNamesTheLedgerAsItsSource(): void
    {
        self::assertSame("Counted: from the rows of Intakt's ledger.", FigureKind::Counted->source());
    }

    public function testAnEstimatedFigureNamesThePriceListAsItsSource(): void
    {
        self::assertSame('Estimated: from the configured price list.', FigureKind::Estimated->source());
    }
}
