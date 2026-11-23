<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudging;
use Intakt\Core\Review\UseCase\JudgeRound\RecordFailedJudgingHandler;
use Intakt\Core\Review\UseCase\JudgeRound\RecordRecurringP1s;
use Intakt\Core\Review\UseCase\JudgeRound\RecurringP1s;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('persistence')]
#[CoversClass(RecurringP1s::class)]
#[CoversClass(RecordRecurringP1s::class)]
#[CoversClass(FailedJudgings::class)]
#[CoversClass(RecordFailedJudgingHandler::class)]
#[CoversClass(JudgedRounds::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(StoredFindings::class)]
#[UsesClass(RecurringP1Found::class)]
#[UsesClass(EscalationVerdictReused::class)]
#[UsesClass(KeptVerdicts::class)]
#[UsesClass(KeptVerdict::class)]
#[UsesClass(EscalationVerdict::class)]
final class RecurringP1sTest extends KernelTestCase
{
    private const string CHANGE = 'recurring-change';
    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private RoundFixture $fixture;

    public function testAP1WithTheSameKeyFlaggedOnASecondHeadRecordsOneRecurringP1Found(): void
    {
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $second = $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1())));

        self::assertEquals([$second => $first], $this->recurrences());
    }

    public function testTheRecurringP1FoundNamesTheRoundsFindingAndTheKeyOfItsJudgeDone(): void
    {
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1())));

        $done = $this->payloadsOf(LedgerEventKind::JudgeDone)[1];
        $found = $this->payloadsOf(LedgerEventKind::RecurringP1Found)[0];

        self::assertIsArray($done['p1_keys']);
        self::assertIsString($found['finding']);
        self::assertSame($done['p1_keys'][$found['finding']], $found['key']);
    }

    public function testAP1FlaggedAgainOnTheSameHeadRecordsNoRecurringP1Found(): void
    {
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->repeatedP1($this->keyedP1())));

        self::assertSame([], $this->recurrences());
    }

    public function testAP1NamingNoRegularFileGetsNoKeyAndNeverCountsAsRecurring(): void
    {
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->unkeyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->unkeyedP1())));

        self::assertSame([], $this->recurrences());
    }

    public function testAP1OfAnotherChangeWithTheSameKeyDoesNotCount(): void
    {
        $this->judged($this->completedAt('another-change', self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->keyedP1()));

        self::assertSame([], $this->recurrences());
    }

    public function testRoundsOnHeadsABAndBJudgedInOrderRecordAnEventForEachRoundOnB(): void
    {
        $a = $this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1());
        $b = $this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1()));
        $again = $this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1()));

        array_map($this->judged(...), [$a, $b, $again]);

        self::assertEquals([$b => $a, $again => $a], $this->recurrences());
    }

    public function testRoundsOnHeadsABAndBJudgedInReverseRecordTheSameEventsAsInOrder(): void
    {
        $a = $this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1());
        $b = $this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1()));
        $again = $this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1()));

        array_map($this->judged(...), [$again, $b, $a]);

        self::assertEquals([$b => $a, $again => $a], $this->recurrences());
    }

    public function testRoundsOnHeadsBAndAAfterAJudgedSecondThirdFirstRecordOnlyTheRoundOnB(): void
    {
        $a = $this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1());
        $b = $this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1()));
        $again = $this->completedAt(self::CHANGE, self::HEAD_A, $this->repeatedP1($this->keyedP1()));

        array_map($this->judged(...), [$b, $again, $a]);

        self::assertEquals([$b => $a], $this->recurrences());
    }

    public function testARoundJudgedBeforeAnEarlierRoundOfTheChangeFindsNothingYet(): void
    {
        $this->completedAs($this->fixture->startAtHead(self::CHANGE, self::HEAD_A), '[]');
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertSame([], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testJudgingTheEarlierRoundLastFindsTheRecurrenceOfTheLaterRoundJudgedBeforeIt(): void
    {
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');

        self::assertEquals([new RecurringP1Found($second, 'F1', 'k', $first)], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($first)));
    }

    public function testReturningToTheFirstHeadJudgedFirstLeavesOnlyTheOtherHeadRecurring(): void
    {
        $a = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $b = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $again = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        array_map(fn (string $round) => $this->completedAs($round, '[]'), [$a, $b, $again]);
        $recurring = $this->fixture->recurringP1s($this->fixture->ledger());
        $events = [];

        foreach ([$again, $b, $a] as $round) {
            $this->appendJudgeDone($round, '["F1"]', '{"F1":"k"}');

            foreach ($recurring->foundWith($this->fixture->round($round)) as $found) {
                $events[] = [$found->round, $found->firstRound];
                $payload = json_encode(['round' => $found->round, 'finding' => $found->finding, 'key' => $found->key, 'first_round' => $found->firstRound], JSON_THROW_ON_ERROR);
                self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::RecurringP1Found, $payload))->succeeded());
            }
        }

        self::assertSame([[$b, $a]], $events);
    }

    public function testALegacyRoundCompletedWithoutAJudgingDoesNotHoldUpALaterRecurrence(): void
    {
        $this->completedAs($this->fixture->startAtHead(self::CHANGE, self::HEAD_A), null);
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertEquals([new RecurringP1Found($second, 'F1', 'k', $first)], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testAnEarlierRoundJudgedWithoutAJudgeDoneDoesNotHoldUpALaterRecurrence(): void
    {
        $judged = $this->fixture->round($this->fixture->startAtHead(self::CHANGE, self::HEAD_A));
        $judged->moveTo(RoundState::Judged->value);
        $this->fixture->entities->flush();
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertEquals([new RecurringP1Found($second, 'F1', 'k', $first)], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testAnEarlierRoundCompletedAndWaitingForItsJudgingHoldsUpALaterRecurrence(): void
    {
        $waiting = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $this->completedAs($waiting, '[]');
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertSame([], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testAnEarlierRoundWhoseJudgingIsParkedInTheFailureTransportHoldsUpNoLaterRecurrence(): void
    {
        $parked = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $this->completedAs($parked, '[]');
        $failed = new InMemoryTransport();
        $failed->send(new Envelope(new JudgeRound($parked)));
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertEquals([new RecurringP1Found($second, 'F1', 'k', $first)], $this->fixture->recurringP1s($this->fixture->ledger(), $failed)->foundWith($this->fixture->round($second)));
    }

    public function testAnEarlierRoundWhoseJudgingFailureIsRecordedHoldsUpNoLaterRecurrence(): void
    {
        $failed = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $this->completedAs($failed, '[]');
        self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::JudgingFailed, '{"round":"'.$failed.'"}'))->succeeded());
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertEquals([new RecurringP1Found($second, 'F1', 'k', $first)], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testRecordingTheFailedJudgingOfAWaitingRoundRecordsTheRecurrenceItHeldBack(): void
    {
        $waiting = $this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1());
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->requiredP1()));
        $second = $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->requiredP1()));
        self::assertSame([], $this->recurrences());

        $commands = self::createStub(MessageBusInterface::class);
        $commands->method('dispatch')->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));

        $this->fixture->failedJudgingRecorder($commands, self::createStub(LoggerInterface::class))(new RecordFailedJudging($waiting));

        self::assertEquals([$second => $first], $this->recurrences());
    }

    // Round 1 has no end state that would release what it held back, so it holds nothing back and its later judging adds its own event to the one recorded before.
    public function testACollectingRoundOneOnAWithRoundsTwoOnBAndThreeOnAJudgedRecordsRoundThreeAndThenRoundTwoOnceRoundOneIsJudged(): void
    {
        $sent = count($this->fixture->bus->messages);
        $one = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $reviewersOfOne = array_slice($this->fixture->bus->messages, $sent);
        $two = $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->requiredP1()));
        $three = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->requiredP1()));
        $beforeOne = $this->recurrences();

        foreach ($reviewersOfOne as $message) {
            $this->fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->keyedP1()], [], [])]));
        }

        $this->judged($one);

        self::assertSame([[$three => $two], [$three => $two, $two => $one]], [$beforeOne, $this->recurrences()]);
    }

    public function testAP1TheJudgeDidNotPutOnTheActListOnTheEarlierHeadDoesNotMakeALaterOneRecurring(): void
    {
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '[]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertSame([], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testAnActEntryWithTheSameKeyOnTheEarlierHeadMakesALaterOneRecurring(): void
    {
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $this->appendJudgeDone($first, '["F1"]', '{"F1":"k"}');
        $this->appendJudgeDone($second, '["F2"]', '{"F2":"k"}');

        self::assertEquals([new RecurringP1Found($second, 'F2', 'k', $first)], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testAJudgeDoneWrittenBeforeKeysWereRecordedFlagsNothing(): void
    {
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $second = $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);
        $appended = $this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"'.$first.'","act":["F1"],"follow_ups":[],"findings":[]}'));
        self::assertTrue($appended->succeeded());
        $this->appendJudgeDone($second, '["F1"]', '{"F1":"k"}');

        self::assertSame([], $this->fixture->recurringP1s($this->fixture->ledger())->foundWith($this->fixture->round($second)));
    }

    public function testFindingTheRecurrencesOfARoundScansTheJudgeDoneEventsOnce(): void
    {
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->keyedP1()));
        $last = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $ledger = new CountingLedger($this->fixture->ledger());

        $this->fixture->recurringP1s($ledger)->foundWith($this->fixture->round($last));

        self::assertSame(1, $ledger->readsOfKind[LedgerEventKind::JudgeDone->value]);
    }

    public function testAThreeRoundTaskOverTwoHeadsPrintsTheJudgeDoneKeysAndARecurringP1FoundInTheLedgerEvents(): void
    {
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->repeatedP1($this->keyedP1())));
        $third = $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1())));
        $keys = $this->payloadsOf(LedgerEventKind::JudgeDone)[2]['p1_keys'];
        self::assertIsArray($keys);
        $key = $keys['F1'];
        self::assertIsString($key);

        $printed = $this->ledgerEvents();

        self::assertStringContainsString(' judge_done {"round":"'.$third.'"', $printed);
        self::assertStringContainsString(' recurring_p1_found {"round":"'.$third.'","finding":"F1","key":"'.$key.'","first_round":"'.$first.'"}', $printed);
        self::assertSame(1, substr_count($printed, ' recurring_p1_found '));
    }

    public function testARecurringP1WhoseKeyHasAKeptVerdictRecordsTheVerdictReusedWithItsOutcome(): void
    {
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->keepVerdictOfFirstRound(EscalationOutcome::Rejected, $first);
        $second = $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1())));

        $reused = $this->payloadsOf(LedgerEventKind::EscalationVerdictReused);

        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $this->keyOfFirstRound(), 'outcome' => 'rejected', 'verdict_round' => $first]], $reused);
    }

    public function testTheVerdictReusedFollowsTheRecurringP1FoundInTheLedger(): void
    {
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->keepVerdictOfFirstRound(EscalationOutcome::Confirmed, $first);
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1())));

        $printed = $this->ledgerEvents();

        self::assertGreaterThan(strpos($printed, ' recurring_p1_found '), strpos($printed, ' escalation_verdict_reused '));
        self::assertStringContainsString('"outcome":"confirmed"', $printed);
    }

    public function testARecurringP1WhoseKeyHasNoKeptVerdictRecordsNoReuse(): void
    {
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->keyedP1())));

        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));
    }

    public function testAKeptVerdictIsNotReusedOnAFindingWhoseTextOrFilesChangedAndSoHasAnotherKey(): void
    {
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->keepVerdictOfFirstRound(EscalationOutcome::Confirmed, $first);
        $edited = new Finding('A', Severity::P1, 'RecordingBus.php', 3, null, 'The bus drops a message.', FixableBy::Builder, false, null, null);

        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($edited)));

        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));
    }

    public function testAP1WhoseTextChangedRecursUnderItsNewKeyWithoutReusingTheVerdictKeptUnderTheOldKey(): void
    {
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1()));
        $this->keepVerdictOfFirstRound(EscalationOutcome::Confirmed, $first);
        $edited = new Finding('A', Severity::P1, 'RecordingBus.php', 3, null, 'The bus drops a message.', FixableBy::Builder, false, null, null);
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->repeatedP1($edited)));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($edited)));

        $found = $this->payloadsOf(LedgerEventKind::RecurringP1Found);

        self::assertSame([1, false, []], [count($found), $this->keyOfFirstRound() === ($found[0]['key'] ?? null), $this->payloadsOf(LedgerEventKind::EscalationVerdictReused)]);
    }

    public function testAP1NamingNoRegularFileGetsNoKeyAndReusesNoKeptVerdict(): void
    {
        $this->fixture->keptVerdicts()->keep('', new EscalationVerdict(EscalationOutcome::Confirmed, 'Seeded.'), 'r1');
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->unkeyedP1()));
        $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->repeatedP1($this->unkeyedP1())));

        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));
    }

    public function testRecordingAFailedJudgingReusesTheKeptVerdictOfTheRecurrenceItReleases(): void
    {
        $waiting = $this->completedAt(self::CHANGE, self::HEAD_A, $this->keyedP1());
        $first = $this->judged($this->completedAt(self::CHANGE, self::HEAD_A, $this->requiredP1()));
        $this->keepVerdictOfFirstRound(EscalationOutcome::Rejected, $first);
        $second = $this->judged($this->completedAt(self::CHANGE, self::HEAD_B, $this->requiredP1()));
        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));

        $commands = self::createStub(MessageBusInterface::class);
        $commands->method('dispatch')->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));
        $this->fixture->failedJudgingRecorder($commands, self::createStub(LoggerInterface::class))(new RecordFailedJudging($waiting));

        self::assertSame([$second], array_column($this->payloadsOf(LedgerEventKind::EscalationVerdictReused), 'round'));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function keepVerdictOfFirstRound(EscalationOutcome $outcome, string $round): void
    {
        $this->fixture->keptVerdicts()->keep($this->keyOfFirstRound(), new EscalationVerdict($outcome, 'Seeded.'), $round);
    }

    private function keyOfFirstRound(): string
    {
        $keys = $this->payloadsOf(LedgerEventKind::JudgeDone)[0]['p1_keys'];
        self::assertIsArray($keys);
        $key = array_first($keys);
        self::assertIsString($key);

        return $key;
    }

    // Names a file of the fixture's checkout, so it gets a key.
    private function keyedP1(): Finding
    {
        return new Finding('A', Severity::P1, 'RecordingBus.php', 3, null, 'The bus loses a message.', FixableBy::Builder, false, null, null);
    }

    // Only this round's reviewer messages, so the rounds started before are not delivered again.
    private function completedAt(string $change, string $head, Finding $finding): string
    {
        $sent = count($this->fixture->bus->messages);
        $round = $this->fixture->startAtHead($change, $head);
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$finding], [], [])]);

        foreach (array_slice($this->fixture->bus->messages, $sent) as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $round;
    }

    // An empty answer, so the fallback puts every P1 on the act list whatever ids the round gave it.
    private function judged(string $round): string
    {
        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($round));

        return $round;
    }

    // Tied to a requirement, so the goalpost rule lets a new P1 of a later round block.
    private function requiredP1(): Finding
    {
        return new Finding('A', Severity::P1, 'RecordingBus.php', 3, null, 'The bus loses a message.', FixableBy::Builder, false, 'Every message is delivered.', null);
    }

    private function unkeyedP1(): Finding
    {
        return new Finding('A', Severity::P1, null, null, null, 'The bus loses a message.', FixableBy::Builder, false, null, null);
    }

    // The install's own console command, because the evidence must be what an operator reads from the database the rounds were recorded in.
    private function ledgerEvents(): string
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    // Under its ledger id and marked as a repeat, because a later round reports an open finding that way and a new id would not reach its judge.
    private function repeatedP1(Finding $finding): Finding
    {
        return new Finding('F1', $finding->severity, $finding->file, $finding->line, null, $finding->text, FixableBy::Builder, true, null, null);
    }

    // Null leaves the reported findings unset, because that is how a round completed before migration Version20261101120000 looks.
    private function completedAs(string $id, ?string $reported): void
    {
        $round = $this->fixture->round($id);
        $round->moveTo(RoundState::Complete->value);

        if (null !== $reported) {
            $round->keepReportedFindings($reported);
        }

        $this->fixture->entities->flush();
    }

    private function appendJudgeDone(string $round, string $act, string $keys): void
    {
        $appended = $this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round":"'.$round.'","act":'.$act.',"follow_ups":[],"findings":[],"p1_keys":'.$keys.'}'));
        self::assertTrue($appended->succeeded());
    }

    // First round by recurring round, compared without order, because round ids made at one clock moment do not sort by creation.
    /**
     * @return array<mixed>
     */
    private function recurrences(): array
    {
        $recurrences = [];

        foreach ($this->payloadsOf(LedgerEventKind::RecurringP1Found) as $found) {
            self::assertIsString($found['round']);
            $recurrences[$found['round']] = $found['first_round'];
        }

        return $recurrences;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloadsOf(LedgerEventKind $kind): array
    {
        return array_values(array_map(static function (RecordedEvent $event): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->event->payload, true, 8, JSON_THROW_ON_ERROR);

            return $payload;
        }, iterator_to_array($this->fixture->ledger()->readOfKind($kind))));
    }
}
