<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger\Contract;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayload;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(LedgerPayload::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(RecordedEvent::class)]
final class LedgerPayloadTest extends TestCase
{
    public function testAnUnreadablePayloadDecodesToAnEmptyArray(): void
    {
        self::assertSame([], LedgerPayload::of($this->recorded('{not json')));
    }

    public function testAPayloadThatIsNotAnObjectDecodesToAnEmptyArray(): void
    {
        self::assertSame([], LedgerPayload::of($this->recorded('"text"')));
    }

    public function testAPayloadNestedDeeperThanTheLimitDecodesToAnEmptyArray(): void
    {
        self::assertSame([], LedgerPayload::of($this->recorded(str_repeat('{"a":', 9).'1'.str_repeat('}', 9))));
    }

    public function testAPayloadAtTheDepthLimitIsDecoded(): void
    {
        self::assertNotSame([], LedgerPayload::of($this->recorded(str_repeat('{"a":', 7).'1'.str_repeat('}', 7))));
    }

    public function testIntegerKeysAreDroppedFromADecodedPayload(): void
    {
        self::assertSame(['project' => 'a'], LedgerPayload::of($this->recorded('{"project":"a","0":"x"}')));
    }

    private function recorded(string $payload): RecordedEvent
    {
        return new RecordedEvent(new UuidV7(), new LedgerEvent(LedgerEventKind::JudgeDone, $payload), new DateTimeImmutable('2026-10-27 12:00:00'));
    }
}
