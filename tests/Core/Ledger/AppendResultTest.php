<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use Intakt\Core\Ledger\Contract\AppendResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AppendResult::class)]
final class AppendResultTest extends TestCase
{
    public function testAnAppendedEventSucceededAndCarriesItsId(): void
    {
        $id = Uuid::v7();

        $result = AppendResult::appended($id);

        self::assertTrue($result->succeeded());
        self::assertSame([$id, null], [$result->id, $result->failure]);
    }

    public function testAFailedAppendDidNotSucceedAndCarriesItsReasonWithoutAnId(): void
    {
        $result = AppendResult::failed('the database is down');

        self::assertFalse($result->succeeded());
        self::assertSame([null, 'the database is down'], [$result->id, $result->failure]);
    }
}
