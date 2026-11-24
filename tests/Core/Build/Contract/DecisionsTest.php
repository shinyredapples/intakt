<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Contract;

use Intakt\Core\Build\Contract\Decisions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Decisions::class)]
final class DecisionsTest extends TestCase
{
    public function testDecisionsKeepTheirGivenOrderAndRepeats(): void
    {
        self::assertSame(['Use SQLite.', 'Keep the API.', 'Use SQLite.'], [...new Decisions('Use SQLite.', 'Keep the API.', 'Use SQLite.')]);
    }

    public function testNoDecisionsAreEmpty(): void
    {
        self::assertSame([true, false], [new Decisions()->isEmpty(), new Decisions('Use SQLite.')->isEmpty()]);
    }
}
