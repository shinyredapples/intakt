<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\FindingKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingKind::class)]
final class FindingKindTest extends TestCase
{
    public function testEachKindIsNamedInTheWordsAReaderSees(): void
    {
        self::assertSame(['Security', 'Logic', 'No test', 'Tidy-up', 'Other'], array_map(static fn (FindingKind $kind): string => $kind->inWords(), FindingKind::cases()));
    }
}
