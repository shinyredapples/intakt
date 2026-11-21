<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Review\Contract\EscalationOutcome;
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
#[UsesClass(VerdictAlreadyKept::class)]
final class KeptVerdictsTest extends KernelTestCase
{
    private KeptVerdicts $verdicts;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->verdicts = new KeptVerdicts($entities, new MockClock('2026-11-21 10:00:00'));
    }

    public function testAKeptVerdictIsFoundByItsKeyWithItsOutcomeAndReasons(): void
    {
        $this->verdicts->keep('finding-one', EscalationOutcome::Rejected, 'The code never runs.', 'round-one');

        $kept = $this->verdicts->ofKey('finding-one');

        self::assertSame([EscalationOutcome::Rejected, 'The code never runs.', 'round-one'], [$kept?->outcome, $kept?->reasons, $kept?->round]);
    }

    public function testAKeptVerdictRecordsWhenItWasKeptInUtc(): void
    {
        $this->verdicts->keep('finding-utc', EscalationOutcome::Confirmed, 'It holds.', 'round-one');

        self::assertSame('2026-11-21T10:00:00+00:00', $this->verdicts->ofKey('finding-utc')?->recordedAt->format('c'));
    }

    public function testAnUnknownKeyFindsNoKeptVerdict(): void
    {
        $this->verdicts->keep('finding-known', EscalationOutcome::Confirmed, 'It holds.', 'round-one');

        self::assertNull($this->verdicts->ofKey('finding-unknown'));
    }

    public function testASecondVerdictForTheSameKeyIsRefusedAndTheFirstStays(): void
    {
        $this->verdicts->keep('finding-twice', EscalationOutcome::Confirmed, 'It holds.', 'round-one');

        try {
            $this->verdicts->keep('finding-twice', EscalationOutcome::Rejected, 'It does not.', 'round-two');
            self::fail('A second verdict for one key was kept.');
        } catch (VerdictAlreadyKept) {
            self::assertSame(EscalationOutcome::Confirmed, $this->verdicts->ofKey('finding-twice')?->outcome);
        }
    }
}
