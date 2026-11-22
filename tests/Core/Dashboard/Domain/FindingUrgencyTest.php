<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingUrgency::class)]
final class FindingUrgencyTest extends TestCase
{
    public function testEachSeverityReadsAsHowSoonToFixIt(): void
    {
        self::assertSame(['Urgent', 'Should fix', 'Nice to have'], array_map(static fn (string $severity): string => FindingUrgency::of($severity)?->words() ?? '', ['P1', 'P2', 'P3']));
    }

    public function testAnUnrecordedOrUnknownSeverityHasNoUrgency(): void
    {
        self::assertSame([null, null], [FindingUrgency::of(null), FindingUrgency::of('P9')]);
    }
}
