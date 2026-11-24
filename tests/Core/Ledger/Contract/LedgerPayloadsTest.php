<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger\Contract;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\PayloadDefaults;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
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

    public function testARowThatIsNotJsonIsSkipped(): void
    {
        self::assertNull($this->payloads()->of($this->row('{"number":1,"change":"feat/1"'), NumberedStart::class));
    }

    public function testARowWithAValueItsEnumDoesNotKnowIsSkipped(): void
    {
        self::assertNull($this->payloads()->of($this->row('{"kind":"stale"}'), KindedStart::class));
    }

    public function testARowWithAValueItsEnumKnowsIsRead(): void
    {
        self::assertEquals(new KindedStart(StartKind::Fresh), $this->payloads()->of($this->row('{"kind":"fresh"}'), KindedStart::class));
    }

    public function testARowWithAValueOfTheWrongTypeIsSkipped(): void
    {
        self::assertNull($this->payloads()->of($this->row('{"number":"many","change":"feat/1"}'), NumberedStart::class));
    }

    public function testAnUnreadableEntryOfAListIsLeftOutAndTheOthersAreKept(): void
    {
        $starts = $this->payloads()->entriesOf($this->row('{"starts":[{"number":1,"change":"a"},{"number":"two","change":"b"},{"number":3,"change":"c"}]}'), 'starts', NumberedStart::class);

        self::assertEquals([new NumberedStart(1, 'a'), new NumberedStart(3, 'c')], $starts);
    }

    public function testAnEntryWithANumberTooLargeToEncodeAgainIsLeftOut(): void
    {
        $starts = $this->payloads()->entriesOf($this->row('{"starts":[{"number":1e999,"change":"a"},{"number":2,"change":"b"}]}'), 'starts', NumberedStart::class);

        self::assertEquals([new NumberedStart(2, 'b')], $starts);
    }

    public function testARowWithoutAListUnderTheKeyHasNoEntries(): void
    {
        self::assertNull($this->payloads()->entriesOf($this->row('{"starts":"none"}'), 'starts', NumberedStart::class));
    }

    public function testASettledUsageRowWithATokenCountThatIsNotANumberIsSkipped(): void
    {
        self::assertNull($this->payloads()->of($this->row($this->settled('"many"')), UsageSettled::class));
    }

    public function testASettledUsageRowWithNumericTokenCountsIsRead(): void
    {
        $settled = $this->payloads()->of($this->row($this->settled('100')), UsageSettled::class);

        self::assertSame(100, $settled?->usages[0]->prompt);
    }

    public function testAJudgeDoneRowWithAFindingOfAnUnknownSeverityIsSkipped(): void
    {
        self::assertNull($this->payloads()->of($this->row($this->judgeDone('P9')), JudgeDone::class));
    }

    public function testAJudgeDoneRowWithAFindingOfAKnownSeverityIsRead(): void
    {
        $done = $this->payloads()->of($this->row($this->judgeDone('P1')), JudgeDone::class);

        self::assertSame(Severity::P1, $done?->findings[0]->severity);
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

    private function settled(string $prompt): string
    {
        return '{"call":"c1","phase":"settled","usages":[{"account":null,"model":"big","prompt":'.$prompt.',"completion":20,"thinking":null,"tool":null,"cached":null,"cache_creation":null,"cache_read":null,"total":120,"remaining":null,"remaining_minute":null,"remaining_month":null}]}';
    }

    private function judgeDone(string $severity): string
    {
        return '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"'.$severity.'","text":"Broken."}]}';
    }

    private function row(string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewStarted, $payload), new DateTimeImmutable('2026-01-01 00:00:00'));
    }
}
