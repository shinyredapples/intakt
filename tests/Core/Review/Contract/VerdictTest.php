<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class VerdictTest extends TestCase
{
    public function testAnIncompleteStreamVerdictBlocksAndNamesTheReason(): void
    {
        $verdict = Verdict::incompleteStream('the stream ended early');

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertStringContainsString('the stream ended early', $verdict->findings[0]->text);
    }
}
