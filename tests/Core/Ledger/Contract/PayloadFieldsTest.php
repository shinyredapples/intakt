<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger\Contract;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(PayloadFields::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(RecordedEvent::class)]
final class PayloadFieldsTest extends TestCase
{
    public function testATextFieldIsReadAsText(): void
    {
        self::assertSame('a', PayloadFields::of($this->recorded('{"project":"a"}'))->text('project'));
    }

    public function testAFieldThatIsNotTextReadsAsNoText(): void
    {
        self::assertNull(PayloadFields::of($this->recorded('{"project":7}'))->text('project'));
    }

    public function testAnIntegerFieldIsReadAsANumber(): void
    {
        self::assertSame(3, PayloadFields::of($this->recorded('{"failures":3}'))->number('failures'));
    }

    public function testAFieldThatIsNotAnIntegerReadsAsNoNumber(): void
    {
        self::assertNull(PayloadFields::of($this->recorded('{"failures":"3"}'))->number('failures'));
    }

    public function testAMissingOrNullFieldIsLacking(): void
    {
        $fields = PayloadFields::of($this->recorded('{"failure":null}'));

        self::assertTrue($fields->lacks('failure'));
        self::assertTrue($fields->lacks('cause'));
    }

    public function testAFieldWithAValueOfAnyTypeIsNotLacking(): void
    {
        self::assertFalse(PayloadFields::of($this->recorded('{"failure":false}'))->lacks('failure'));
    }

    public function testAnUnreadablePayloadHasNoFields(): void
    {
        self::assertTrue(PayloadFields::of($this->recorded('{not json'))->lacks('project'));
    }

    public function testAPayloadThatIsNotAnObjectHasNoFields(): void
    {
        self::assertTrue(PayloadFields::of($this->recorded('["a"]'))->lacks('0'));
    }

    public function testAPayloadNestedDeeperThanTheLimitHasNoFields(): void
    {
        self::assertNull(PayloadFields::of($this->recorded('{"project":"a","n":'.str_repeat('{"a":', 8).'1'.str_repeat('}', 8).'}'))->text('project'));
    }

    public function testAPayloadAtTheDepthLimitIsRead(): void
    {
        self::assertSame('a', PayloadFields::of($this->recorded('{"project":"a","n":'.str_repeat('{"a":', 6).'1'.str_repeat('}', 6).'}'))->text('project'));
    }

    public function testIntegerKeysAreNotFields(): void
    {
        self::assertTrue(PayloadFields::of($this->recorded('{"project":"a","0":"x"}'))->lacks('0'));
    }

    private function recorded(string $payload): RecordedEvent
    {
        return new RecordedEvent(new UuidV7(), new LedgerEvent(LedgerEventKind::JudgeDone, $payload), new DateTimeImmutable('2026-10-27 12:00:00'));
    }
}
