<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\VerdictAlreadyKept;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;

#[Group('persistence')]
#[CoversClass(KeptVerdicts::class)]
#[UsesClass(KeptVerdict::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(VerdictAlreadyKept::class)]
final class KeptVerdictsTest extends KernelTestCase
{
    private KeptVerdicts $verdicts;

    private EntityManagerInterface $entities;

    public function testAKeptVerdictIsFoundByItsKeyWithItsOutcomeAndReasons(): void
    {
        $this->verdicts->keep('finding-one', new EscalationVerdict(EscalationOutcome::Rejected, 'The code never runs.'), 'round-one');

        $kept = $this->verdicts->ofKey('finding-one');

        self::assertSame([EscalationOutcome::Rejected, 'The code never runs.', 'round-one'], [$kept?->outcome, $kept?->reasons, $kept?->round]);
    }

    public function testAKeptVerdictRecordsWhenItWasKeptInUtc(): void
    {
        $this->verdicts->keep('finding-utc', new EscalationVerdict(EscalationOutcome::Confirmed, 'It holds.'), 'round-one');

        self::assertSame('2026-11-21T10:00:00+00:00', $this->verdicts->ofKey('finding-utc')?->recordedAt->format('c'));
    }

    public function testAKeptVerdictReadBackFromTheDatabaseKeepsItsUtcMomentInAnotherDefaultZone(): void
    {
        $this->verdicts->keep('finding-reloaded', new EscalationVerdict(EscalationOutcome::Confirmed, 'It holds.'), 'round-one');
        $this->entities->clear();
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $read = $this->verdicts->ofKey('finding-reloaded')?->recordedAt->format('c');
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertSame('2026-11-21T10:00:00+00:00', $read);
    }

    public function testASecondInsertForAKeyIsRefusedAsAlreadyKeptAndTheEntityManagerStaysOpen(): void
    {
        $this->verdicts->keep('finding-raced', new EscalationVerdict(EscalationOutcome::Confirmed, 'It holds.'), 'round-one');

        try {
            $this->verdicts->keep('finding-raced', new EscalationVerdict(EscalationOutcome::Rejected, 'It does not.'), 'round-two');
            self::fail('A second verdict for one key was kept.');
        } catch (VerdictAlreadyKept) {
            self::assertTrue($this->entities->isOpen());
        }
    }

    public function testAVerdictKeptAndReadInATransactionThatRollsBackIsNoLongerFoundForItsKey(): void
    {
        $connection = $this->entities->getConnection();
        $connection->beginTransaction();
        $this->verdicts->keep('finding-rolled-back', new EscalationVerdict(EscalationOutcome::Rejected, 'It does not.'), 'round-one');
        $readInside = $this->verdicts->ofKey('finding-rolled-back')?->outcome;
        $connection->rollBack();

        self::assertSame([EscalationOutcome::Rejected, null], [$readInside, $this->verdicts->ofKey('finding-rolled-back')]);
    }

    public function testAnUnknownKeyFindsNoKeptVerdict(): void
    {
        $this->verdicts->keep('finding-known', new EscalationVerdict(EscalationOutcome::Confirmed, 'It holds.'), 'round-one');

        self::assertNull($this->verdicts->ofKey('finding-unknown'));
    }

    public function testASecondVerdictForTheSameKeyIsRefusedAndTheFirstStays(): void
    {
        $this->verdicts->keep('finding-twice', new EscalationVerdict(EscalationOutcome::Confirmed, 'It holds.'), 'round-one');

        try {
            $this->verdicts->keep('finding-twice', new EscalationVerdict(EscalationOutcome::Rejected, 'It does not.'), 'round-two');
            self::fail('A second verdict for one key was kept.');
        } catch (VerdictAlreadyKept) {
            self::assertSame(EscalationOutcome::Confirmed, $this->verdicts->ofKey('finding-twice')?->outcome);
        }
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::rowsIn(self::getContainer());
        $this->entities = $entities;
        $this->verdicts = new KeptVerdicts($entities, new MockClock('2026-11-21 10:00:00'));
    }
}
