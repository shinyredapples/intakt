<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger\Contract;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\PayloadDefaults;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Tests\Support\StoredSerializerFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerPayloads::class)]
#[CoversClass(PayloadDefaults::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(RecordedEvent::class)]
final class LedgerPayloadsTest extends TestCase
{
    public function testAKeyTheRowLacksTakesItsDefault(): void
    {
        $start = $this->payloads()->of($this->row('{"number":1}'), NumberedStart::class, new PayloadDefaults(change: 'none'));

        self::assertEquals(new NumberedStart(1, 'none'), $start);
    }

    public function testAKeyTheRowHasKeepsTheRowsValueOverTheDefault(): void
    {
        $start = $this->payloads()->of($this->row('{"number":1,"change":"feat/1"}'), NumberedStart::class, new PayloadDefaults(change: 'none'));

        self::assertEquals(new NumberedStart(1, 'feat/1'), $start);
    }

    public function testARowLackingAKeyWithoutADefaultIsSkipped(): void
    {
        self::assertNull($this->payloads()->of($this->row('{"number":1}'), NumberedStart::class));
    }

    public function testAnUnreadKeyTakesTheGivenValueEvenWhereTheRowHasOne(): void
    {
        $start = $this->payloads()->ofWithout($this->row('{"number":1,"change":["unreadable"]}'), NumberedStart::class, new PayloadDefaults(change: 'given'));

        self::assertEquals(new NumberedStart(1, 'given'), $start);
    }

    public function testAPositionalDefaultIsRefusedBecauseNoPayloadKeyCouldMatchIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayloadDefaults('none');
    }

    public function testANamedDefaultKeepsItsParameterName(): void
    {
        self::assertSame(['change' => 'none'], iterator_to_array(new PayloadDefaults(change: 'none')));
    }

    private function payloads(): LedgerPayloads
    {
        return new LedgerPayloads(StoredSerializerFactory::create());
    }

    private function row(string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewStarted, $payload), new DateTimeImmutable('2026-01-01 00:00:00'));
    }
}
