<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\HistoryRow;
use Intakt\Core\Dashboard\Domain\HistorySource;
use Intakt\Core\Dashboard\Domain\HistoryTopic;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(HistoryRow::class)]
#[UsesClass(HistoryTopic::class)]
#[UsesClass(PayloadFields::class)]
final class HistoryRowTest extends TestCase
{
    public function testARowShowsBeforeAndAfterOnlyWhenTheEventCarriesBothValues(): void
    {
        $both = HistoryRow::of($this->recorded(['before' => 'low', 'after' => 'high']));
        $one = HistoryRow::of($this->recorded(['after' => 'high']));

        self::assertTrue($both->showsChange());
        self::assertFalse($one->showsChange());
    }

    public function testARowShowsNoChangeWhenBeforeAndAfterAreEqual(): void
    {
        self::assertFalse(HistoryRow::of($this->recorded(['before' => 'low', 'after' => 'low']))->showsChange());
    }

    public function testAnImpossibleExpiryDateIsUnreadableRatherThanRolledOver(): void
    {
        $impossible = HistoryRow::of($this->recorded(['expires_at' => '2026-02-30T10:00:00+00:00']));
        $lastDay = HistoryRow::of($this->recorded(['expires_at' => '2026-02-28T10:00:00+00:00']));

        self::assertNull($impossible->expires);
        self::assertSame('2026-02-28', $lastDay->expires?->format('Y-m-d'));
    }

    public function testAnExpiryHoldingANulByteIsUnreadableRatherThanAnError(): void
    {
        self::assertNull(HistoryRow::of($this->recorded(['expires_at' => "2026-12-01T12:00:00+00:00\0"]))->expires);
    }

    public function testAnEmptyFingerprintStillHidesBeforeAndAfter(): void
    {
        $row = HistoryRow::of($this->recorded(['fingerprint' => '', 'before' => 'sk-old-secret', 'after' => 'sk-new-secret']));

        self::assertNull($row->before);
        self::assertNull($row->after);
    }

    public function testARowShowsTheExpiryOnlyWhenTheEventHasOne(): void
    {
        $with = HistoryRow::of($this->recorded(['expires_at' => '2026-12-01T10:00:00+02:00']));
        $without = HistoryRow::of($this->recorded([]));
        $unreadable = HistoryRow::of($this->recorded(['expires_at' => 'not a date']));

        self::assertSame('2026-12-01 08:00:00', $with->expires?->format('Y-m-d H:i:s'));
        self::assertNull($without->expires);
        self::assertNull($unreadable->expires);
    }

    public function testARowShowsTheRecordedEndOfAnAccountCooldownAsItsExpiry(): void
    {
        $row = HistoryRow::of(new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::AccountCooledDown, '{"account":"sample","until":"2026-12-01T10:00:00+00:00"}'), new DateTimeImmutable('2026-12-01T09:00:00+00:00')));

        self::assertSame('2026-12-01 10:00:00', $row->expires?->format('Y-m-d H:i:s'));
        self::assertContains(HistoryTopic::Limits, $row->topics());
    }

    public function testASecretValueAppearsInARowOnlyAsItsFingerprint(): void
    {
        $row = HistoryRow::of($this->recorded(['fingerprint' => 'ab12cd', 'before' => 'sk-old-secret', 'after' => 'sk-new-secret']));

        self::assertSame('ab12cd', $row->fingerprint);
        self::assertNull($row->before);
        self::assertNull($row->after);
        self::assertFalse($row->showsChange());
    }

    public function testARowNamesTheActorOrIntaktAndTheProject(): void
    {
        $named = HistoryRow::of($this->recorded(['actor' => 'owner', 'project' => 'alpha']));
        $unnamed = HistoryRow::of($this->recorded('not json'));

        self::assertSame('owner', $named->who);
        self::assertSame('alpha', $named->project);
        self::assertSame('Intakt', $unnamed->who);
        self::assertNull($unnamed->project);
        self::assertSame('Owner asked', $unnamed->label());
    }

    public function testAnEmptyActorOnASecretChangeIsIntaktAndTheValuesStayHidden(): void
    {
        $row = HistoryRow::of($this->recorded(['actor' => '', 'project' => '', 'fingerprint' => 'abc', 'before' => 'previous', 'after' => 'next']));

        self::assertSame(['Intakt', null, false], [$row->who, $row->project, $row->showsChange()]);
    }

    public function testAnActorOrProjectOfOnlySpacesReadsAsUnset(): void
    {
        $row = HistoryRow::of($this->recorded(['actor' => '   ', 'project' => " \t"]));

        self::assertSame(['Intakt', null], [$row->who, $row->project]);
    }

    #[TestWith(["\f"])]
    #[TestWith(["\u{00A0}"])]
    #[TestWith(["\u{2003}\u{3000}\n"])]
    #[TestWith(["\u{200B}\u{FEFF}"])]
    public function testAnActorOrProjectOfOnlyUnicodeWhitespaceReadsAsUnset(string $blank): void
    {
        $row = HistoryRow::of($this->recorded(['actor' => $blank, 'project' => $blank]));

        self::assertSame(['Intakt', HistorySource::Intakt, null], [$row->who, $row->source, $row->project]);
        self::assertNotContains(HistoryTopic::Projects, $row->topics());
    }

    public function testANameWithANoBreakSpaceInsideItIsKeptAsWritten(): void
    {
        $row = HistoryRow::of($this->recorded(['actor' => "owner\u{00A0}one", 'project' => "alpha\u{00A0}beta"]));

        self::assertSame(["owner\u{00A0}one", "alpha\u{00A0}beta"], [$row->who, $row->project]);
    }

    /**
     * @param array<string, string>|string $payload
     */
    private function recorded(array|string $payload): RecordedEvent
    {
        $json = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        return new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::OwnerAsked, $json), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
