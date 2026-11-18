<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingKey;
use Intakt\Core\Review\Domain\RecurringP1;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\JudgeRound\RecurringP1s;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(RecurringP1s::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(RecurringP1Found::class)]
#[UsesClass(RecurringP1::class)]
#[UsesClass(FindingKey::class)]
#[UsesClass(FindingKeys::class)]
#[UsesClass(JudgedRounds::class)]
#[UsesClass(JudgeRoundHandler::class)]
#[UsesClass(JudgeRound::class)]
#[UsesClass(JudgedFindings::class)]
#[UsesClass(RoundLedgerReading::class)]
#[UsesClass(StoredFindings::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReviewRound::class)]
final class RecurringP1sTest extends KernelTestCase
{
    private const string LATER_HEAD = 'dddddddddddddddddddddddddddddddddddddddd';

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAP1FlaggedInTheSamePlaceOnTheNextHeadIsRecordedAsRecurring(): void
    {
        $first = $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', 'RoundFixture::judge'));
        $second = $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('RoundFixture.php', 'RoundFixture::judge'));

        $found = $this->recurringP1s();

        self::assertCount(1, $found);
        self::assertSame(
            [$second->id()->toRfc4122(), 2, 'F2', $first->id()->toRfc4122(), 1, 'F1', 'RoundFixture.php', 'RoundFixture::judge'],
            [$found[0]['round'], $found[0]['number'], $found[0]['finding'], $found[0]['earlier_round'], $found[0]['earlier_number'], $found[0]['earlier_finding'], $found[0]['file'], $found[0]['symbol']],
        );
    }

    public function testAP1TheReviewerMarksAsARepeatOnTheNextHeadIsRecordedAsRecurringUnderItsOneId(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $repeat = new Finding('F1', Severity::P1, 'RoundFixture.php', 9, null, 'The judging is still not restart safe.', FixableBy::Builder, true, null, null);
        $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $repeat);

        $found = $this->recurringP1s();

        self::assertSame([['F1', 'F1']], array_map(static fn (array $one): array => [$one['finding'], $one['earlier_finding']], $found));
    }

    public function testAP1TheGoalpostRuleLoweredOnTheNextHeadIsNotRecurring(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $this->judgedRoundAt(SampleIdentity::NEW_HEAD, new Finding('A', Severity::P1, 'RoundFixture.php', 3, null, 'The judging is not restart safe.', FixableBy::Builder, false, null, null));

        self::assertSame([], $this->recurringP1s());
    }

    public function testTheRecurringP1CarriesTheKeyOfItsTextAndOfTheFileAtTheHead(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $second = $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('RoundFixture.php', null));

        self::assertSame(new FindingKeys()->keyOf($this->p1('RoundFixture.php', null), $second->checkoutRoot()), $this->recurringP1s()[0]['key']);
        self::assertNotNull($this->recurringP1s()[0]['key']);
    }

    public function testARecurringP1NamingNoRegularFileOfTheCheckoutIsRecordedWithoutAKey(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('src/Thing.php', null));
        $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('src/Thing.php', null));

        self::assertNull($this->recurringP1s()[0]['key']);
    }

    public function testAP1AtAnotherPlaceOnTheNextHeadIsNotRecurring(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('VerdictPerRole.php', null));

        self::assertSame([], $this->recurringP1s());
    }

    public function testAP1FlaggedAgainOnTheSameHeadIsNotRecurring(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));

        self::assertSame([], $this->recurringP1s());
    }

    public function testTheFirstRoundOfAChangeRecordsNoRecurringP1(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));

        self::assertSame([LedgerEventKind::JudgeDone], array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => in_array($kind, [LedgerEventKind::JudgeDone, LedgerEventKind::RecurringP1Found], true))));
    }

    public function testAP1FlaggedAgainAfterAHeadThatDidNotFlagItIsRecurring(): void
    {
        $first = $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('VerdictPerRole.php', null));
        $third = $this->judgedRoundAt(self::LATER_HEAD, $this->p1('RoundFixture.php', null));

        self::assertSame([[$third->number(), $first->number()]], $this->pairs());
    }

    public function testAP1FlaggedOnThreeHeadsIsPairedWithTheNewestEarlierHeadEachTime(): void
    {
        foreach ([SampleIdentity::HEAD, SampleIdentity::NEW_HEAD, self::LATER_HEAD] as $head) {
            $this->judgedRoundAt($head, $this->p1('RoundFixture.php', null));
        }

        self::assertSame([[2, 1], [3, 2]], $this->pairs());
    }

    public function testTwoHeadsStillRecurWhenTheNewerHeadIsJudgedFirst(): void
    {
        $first = $this->reviewedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $second = $this->reviewedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('RoundFixture.php', null));

        $this->judge($second);
        $this->judge($first);

        self::assertSame([[2, 1]], $this->pairs());
        self::assertSame($second, $this->recurringP1s()[0]['round']);
    }

    public function testARoundJudgedLateIsNotPairedWithALaterRoundWhoseRecurrenceIsRecorded(): void
    {
        $rounds = array_map(fn (string $head): string => $this->reviewedRoundAt($head, $this->p1('RoundFixture.php', null)), [SampleIdentity::HEAD, SampleIdentity::NEW_HEAD, self::LATER_HEAD]);

        $this->judge($rounds[0]);
        $this->judge($rounds[2]);
        $this->judge($rounds[1]);

        self::assertSame([[3, 1], [2, 1]], $this->pairs());
    }

    public function testARecurringP1IsFoundBesideAnotherFindingOnItsLine(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', 'RoundFixture::judge'));
        $other = new Finding('B', Severity::P2, 'RoundFixture.php', 3, 'RoundFixture::start', 'Starting has unclear output.', FixableBy::Builder, false, 'The start message explains the outcome.', null);
        $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $other, $this->p1('RoundFixture.php', 'RoundFixture::judge'));

        self::assertSame([['F3', 'RoundFixture::judge']], array_map(static fn (array $one): array => [$one['finding'], $one['symbol']], $this->recurringP1s()));
    }

    public function testAJudgingDeliveredAgainRecordsNoSecondRecurringP1(): void
    {
        $this->judgedRoundAt(SampleIdentity::HEAD, $this->p1('RoundFixture.php', null));
        $second = $this->judgedRoundAt(SampleIdentity::NEW_HEAD, $this->p1('RoundFixture.php', null));

        $this->fixture->judge(new VerdictPerRole([]))(new JudgeRound($second->id()->toRfc4122()));

        self::assertCount(1, $this->recurringP1s());
    }

    private function judgedRoundAt(string $head, Finding ...$findings): ReviewRound
    {
        $roundId = $this->reviewedRoundAt($head, ...$findings);
        $this->judge($roundId);

        return $this->fixture->round($roundId);
    }

    private function reviewedRoundAt(string $head, Finding ...$findings): string
    {
        $delivered = count($this->fixture->bus->messages);
        $roundId = $this->fixture->startAtHead(RoundFixture::CHANGE, $head);
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, array_values($findings), [], [])]);

        foreach (array_slice($this->fixture->bus->messages, $delivered) as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $roundId;
    }

    private function judge(string $roundId): void
    {
        $this->fixture->judge(new VerdictPerRole([]))(new JudgeRound($roundId));
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function pairs(): array
    {
        return array_map(static fn (array $one): array => [$one['number'], $one['earlier_number']], $this->recurringP1s());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recurringP1s(): array
    {
        $events = array_filter(iterator_to_array($this->fixture->ledger()->readInOrder()), static fn (RecordedEvent $event): bool => LedgerEventKind::RecurringP1Found === $event->event->kind);

        return array_values(array_map(static function (RecordedEvent $event): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->event->payload, true, 8, JSON_THROW_ON_ERROR);

            return $payload;
        }, $events));
    }

    // With the requirement it breaks, so the goalpost rule keeps a new P1 of a later round a P1.
    private function p1(string $file, ?string $symbol): Finding
    {
        return new Finding('A', Severity::P1, $file, 3, $symbol, 'The judging is not restart safe.', FixableBy::Builder, false, 'A judging survives a restart.', null);
    }
}
