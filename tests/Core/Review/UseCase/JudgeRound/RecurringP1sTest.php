<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FlaggedRound;
use Intakt\Core\Review\Domain\RecurringP1;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudging;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudgingHandler;
use Intakt\Core\Review\UseCase\JudgeRound\RecurringP1s;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\HandBack\RecordingLedger;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('persistence')]
#[CoversClass(RecurringP1s::class)]
#[CoversClass(JudgedRounds::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(StoredFindings::class)]
#[CoversClass(ReviewRound::class)]
#[CoversClass(RecordFailedJudgingHandler::class)]
#[CoversClass(FailedJudgings::class)]
#[UsesClass(RecordFailedJudging::class)]
#[UsesClass(RecurringP1::class)]
#[UsesClass(FlaggedRound::class)]
#[UsesClass(RecurringP1Found::class)]
#[UsesClass(JudgeDone::class)]
final class RecurringP1sTest extends KernelTestCase
{
    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string HEAD_C = 'dddddddddddddddddddddddddddddddddddddddd';
    private const string HEAD_D = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const string CHANGE_LOCK = 'UPDATE change_findings';

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAP1FlaggedAtTheSamePlaceOnASecondHeadIsRecordedAsRecurring(): void
    {
        $this->judge($this->roundAt(self::HEAD_A));
        $second = $this->roundAt(self::HEAD_B);

        $this->judge($second);

        $found = $this->recurrences();
        self::assertSame([$second], array_column($found, 'round'));
        self::assertSame($this->judgeDoneOf($second)['p1_keys'][$found[0]['finding']], $found[0]['key']);
    }

    public function testAP1FlaggedAgainOnTheSameHeadIsNotRecurring(): void
    {
        $this->judge($this->roundAt(self::HEAD_A));

        $this->judge($this->roundAt(self::HEAD_A));

        self::assertSame([], $this->recurrences());
    }

    public function testRoundsOnHeadsABBJudgedInOrderRecordOneRecurrenceForEachLaterRound(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B), $this->roundAt(self::HEAD_B)];

        array_map($this->judge(...), [$first, $second, $third]);

        self::assertSame([$second, $third], array_column($this->recurrences(), 'round'));
    }

    public function testRoundsOnHeadsABBJudgedInReverseOrderRecordTheSameTwoRecurrences(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B), $this->roundAt(self::HEAD_B)];

        array_map($this->judge(...), [$third, $second, $first]);

        $rounds = array_column($this->recurrences(), 'round');
        sort($rounds);
        $expected = [$second, $third];
        sort($expected);
        self::assertSame($expected, $rounds);
    }

    public function testRoundsOnHeadsABAJudgedInOrderRecordOnlyTheRoundOnB(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B), $this->roundAt(self::HEAD_A)];

        array_map($this->judge(...), [$first, $second, $third]);

        self::assertSame([$second], array_column($this->recurrences(), 'round'));
    }

    public function testRoundsOnHeadsABAJudgedInReverseOrderRecordOnlyTheRoundOnB(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B), $this->roundAt(self::HEAD_A)];

        array_map($this->judge(...), [$third, $second, $first]);

        self::assertSame([$second], array_column($this->recurrences(), 'round'));
    }

    public function testARoundWhoseJudgingFailedForGoodHoldsNoRecurrenceBack(): void
    {
        $failed = $this->roundAt(self::HEAD_C);
        (void) $this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::JudgingFailed, json_encode(['round' => $failed], JSON_THROW_ON_ERROR)));
        $this->judge($this->roundAt(self::HEAD_A));
        $third = $this->roundAt(self::HEAD_B);

        $this->judge($third);

        self::assertSame([$third], array_column($this->recurrences(), 'round'));
    }

    public function testARecurrenceHeldBackByAnEarlierRoundIsRecordedWhenThatRoundsJudgingFailsForGood(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_C), $this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B)];
        array_map($this->judge(...), [$second, $third]);
        $heldBack = $this->recurrences();

        $this->failForGood($first);

        self::assertSame([[], [$third]], [$heldBack, array_column($this->recurrences(), 'round')]);
    }

    public function testARoundJudgedAfterAnEarlierRoundsJudgingFailedRecordsNoRecurrenceTwice(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_C), $this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B)];
        array_map($this->judge(...), [$second, $third]);
        $this->failForGood($first);
        $fourth = $this->roundAt(self::HEAD_D);

        $this->judge($fourth);

        self::assertSame([$third, $fourth], array_column($this->recurrences(), 'round'));
    }

    public function testARoundWhoseJudgingIsParkedHoldsNoRecurrenceBack(): void
    {
        [$first, $second, $third] = [$this->roundAt(self::HEAD_C), $this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B)];
        $this->judge($second);
        $parked = new InMemoryTransport();
        $parked->send(new Envelope(new JudgeRound($first)));
        $keys = $this->judgeDoneOf($second)['p1_keys'];

        $found = $this->fixture->recurringP1s($this->fixture->ledger(), $parked)->foundWhenJudged($this->fixture->round($third), new JudgeDone($third, array_keys($keys), [], [], p1Keys: $keys));

        self::assertSame([$third], array_column($found, 'round'));
    }

    public function testARoundStillCollectingHoldsNoRecurrenceBack(): void
    {
        $this->fixture->startAtHead(RoundFixture::CHANGE, self::HEAD_C);
        $this->judge($this->roundAt(self::HEAD_A));
        $third = $this->roundAt(self::HEAD_B);

        $this->judge($third);

        self::assertSame([$third], array_column($this->recurrences(), 'round'));
    }

    public function testRoundsOnHeadsABAWithTheFirstStillCollectingRecordTheThirdNowAndTheSecondWhenTheFirstIsJudged(): void
    {
        $sent = count($this->fixture->bus->messages);
        $first = $this->fixture->startAtHead(RoundFixture::CHANGE, self::HEAD_A);
        $collecting = array_slice($this->fixture->bus->messages, $sent);
        [$second, $third] = [$this->roundAt(self::HEAD_B), $this->roundAt(self::HEAD_A)];
        array_map($this->judge(...), [$second, $third]);
        $beforeTheFirst = array_column($this->recurrences(), 'round');

        $this->deliver($collecting);
        $this->judge($first);

        self::assertSame([[$third], [$third, $second]], [$beforeTheFirst, array_column($this->recurrences(), 'round')]);
    }

    public function testARoundJudgedWhileAnotherRoundOfTheChangeCommitsItsJudgingRecordsTheRecurrence(): void
    {
        [$first, $second] = [$this->roundAt(self::HEAD_A), $this->roundAt(self::HEAD_B)];
        $ledger = new RecordingLedger();
        $judgeTheFirst = function () use ($ledger, $first): void {
            $this->fixture->judgeOn($this->fixture->entities, $ledger, $this->scripted())(new JudgeRound($first));
        };
        $worker = $this->fixture->entitiesThrough(new WatchedStatements(self::CHANGE_LOCK, $judgeTheFirst));

        $this->fixture->judgeOn($worker, $ledger, $this->scripted())(new JudgeRound($second));

        self::assertSame([$second], array_column($this->recurrences($ledger), 'round'));
    }

    // The order MySQL needs: a plain read before the change's lock fixes a REPEATABLE READ snapshot that misses a judging committed while the lock was awaited.
    public function testAJudgingTakesTheChangesLockBeforeItReadsAnythingButItsRound(): void
    {
        $round = $this->roundAt(self::HEAD_A);
        $watched = new WatchedStatements(self::CHANGE_LOCK, static fn (): null => null);

        $this->fixture->judgeOn($this->fixture->entitiesThrough($watched), new RecordingLedger(), $this->scripted())(new JudgeRound($round));

        $lock = array_find_key($watched->seen, static fn (string $sql): bool => str_starts_with($sql, self::CHANGE_LOCK));
        self::assertIsInt($lock);
        self::assertStringContainsString('FROM review_round', $watched->seen[$lock - 1]);
        self::assertSame([], array_filter(array_slice($watched->seen, 0, $lock), static fn (string $sql): bool => str_contains($sql, 'change_findings')));
    }

    public function testAP1NamingNoFileIsNeverRecurring(): void
    {
        $finding = new Finding('A', Severity::P1, null, null, null, 'The guard is missing.', FixableBy::Builder, false, null, null, regression: true);
        $this->judge($this->roundAt(self::HEAD_A, $finding));

        $this->judge($this->roundAt(self::HEAD_B, $finding));

        self::assertSame([], $this->recurrences());
    }

    public function testARoundOfAnotherChangeAtTheSamePlaceDoesNotCount(): void
    {
        $this->judge($this->roundAt(self::HEAD_A, $this->keyedP1(), 'feat/other'));

        $this->judge($this->roundAt(self::HEAD_B));

        self::assertSame([], $this->recurrences());
    }

    public function testARedeliveredJudgingRecordsNoSecondRecurrence(): void
    {
        $this->judge($this->roundAt(self::HEAD_A));
        $second = $this->roundAt(self::HEAD_B);
        $this->judge($second);

        $this->judge($second);

        self::assertCount(1, $this->recurrences());
    }

    public function testTheRecurrenceIsRecordedRightAfterTheJudgeDoneOfItsRound(): void
    {
        $this->judge($this->roundAt(self::HEAD_A));

        $this->judge($this->roundAt(self::HEAD_B));

        $kinds = array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => in_array($kind, [LedgerEventKind::JudgeDone, LedgerEventKind::RecurringP1Found], true)));
        self::assertSame([LedgerEventKind::JudgeDone, LedgerEventKind::JudgeDone, LedgerEventKind::RecurringP1Found], $kinds);
    }

    public function testFindingTheRecurrencesReadsTheJudgeDoneEventsOnceForAllOtherRounds(): void
    {
        $this->judge($this->roundAt(self::HEAD_A));
        $this->judge($this->roundAt(self::HEAD_B));
        $third = $this->fixture->round($this->roundAt(self::HEAD_B));
        $ledger = new CountingLedger($this->fixture->ledger());

        $found = $this->fixture->recurringP1s($ledger)->foundWhenJudged($third, new JudgeDone($third->id()->toRfc4122(), [], [], []));

        self::assertSame([], $found);
        self::assertSame([LedgerEventKind::JudgeDone->value => 1, LedgerEventKind::RecurringP1Found->value => 1], $ledger->readsOfKind);
    }

    private function roundAt(string $head, ?Finding $finding = null, string $change = RoundFixture::CHANGE): string
    {
        $sent = count($this->fixture->bus->messages);
        $roundId = $this->fixture->startAtHead($change, $head);
        $this->deliver(array_slice($this->fixture->bus->messages, $sent), $finding);

        return $roundId;
    }

    /**
     * @param list<ReviewWithReviewer> $messages
     */
    private function deliver(array $messages, ?Finding $finding = null): void
    {
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding ?? $this->keyedP1()], [], [])]);

        foreach ($messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    private function judge(string $roundId): void
    {
        $this->fixture->judge($this->scripted())(new JudgeRound($roundId));
    }

    private function failForGood(string $roundId): void
    {
        $this->fixture->failedJudgingRecorder(self::getContainer()->get('command.bus'), self::createStub(LoggerInterface::class))(new RecordFailedJudging($roundId));
    }

    private function scripted(): ScriptedJudgement
    {
        return new ScriptedJudgement(new Judgement([]));
    }

    // Names a file of the fixture's checkout, so the P1 gets a key, and is a regression, so the goalpost rule keeps it a P1 in a later round.
    private function keyedP1(): Finding
    {
        return new Finding('A', Severity::P1, 'RoundFixture.php', 3, null, 'The guard in RoundFixture.php is missing.', FixableBy::Builder, false, null, null, regression: true);
    }

    /**
     * @return list<array{round: string, finding: string, key: string}>
     */
    private function recurrences(?Ledger $ledger = null): array
    {
        /** @var list<array{round: string, finding: string, key: string}> $payloads */
        $payloads = array_map(static fn (RecordedEvent $event): mixed => json_decode($event->event->payload, true, 8, JSON_THROW_ON_ERROR), iterator_to_array(($ledger ?? $this->fixture->ledger())->readOfKind(LedgerEventKind::RecurringP1Found), false));

        return $payloads;
    }

    /**
     * @return array{p1_keys: array<string, ?string>}
     */
    private function judgeDoneOf(string $roundId): array
    {
        foreach ($this->fixture->ledger()->readOfKind(LedgerEventKind::JudgeDone) as $event) {
            /** @var array{round: string, p1_keys: array<string, ?string>} $payload */
            $payload = json_decode($event->event->payload, true, 8, JSON_THROW_ON_ERROR);

            if ($roundId === $payload['round']) {
                return $payload;
            }
        }

        self::fail('No judge_done for '.$roundId);
    }
}
