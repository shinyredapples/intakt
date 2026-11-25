<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Contract;

use Intakt\Core\HandBack\Contract\MissingEvidenceItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingEvidenceItems::class)]
final class MissingEvidenceItemsTest extends TestCase
{
    public function testMissingItemsAreReferencedAsEvidenceNumbersJoinedByCommas(): void
    {
        self::assertSame('E1, E3', new MissingEvidenceItems(1, 3)->references());
    }

    public function testMissingItemsKeepTheirNumbersInOrder(): void
    {
        self::assertSame([2, 5], [...new MissingEvidenceItems(2, 5)]);
    }

    public function testNoMissingItemsIsEmpty(): void
    {
        self::assertTrue(new MissingEvidenceItems()->isEmpty());
    }

    public function testOneMissingItemIsNotEmpty(): void
    {
        self::assertFalse(new MissingEvidenceItems(1)->isEmpty());
    }
}
