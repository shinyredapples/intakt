<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use Intakt\Core\Dashboard\Infrastructure\GlossaryExplanations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(GlossaryExplanations::class)]
#[UsesClass(GlossaryTerm::class)]
final class GlossaryExplanationsTest extends TestCase
{
    public function testAKnownTermGetsItsStandardExplanation(): void
    {
        self::assertSame(GlossaryTerm::WeeklyShare->explanation(), new GlossaryExplanations()->of('share'));
    }

    public function testAnUnknownTermIsRefused(): void
    {
        $this->expectException(ValueError::class);

        new GlossaryExplanations()->of('floor');
    }
}
