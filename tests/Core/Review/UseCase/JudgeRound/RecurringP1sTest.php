<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('persistence')]
#[CoversClass(RecurringP1s::class)]
#[CoversClass(JudgedRounds::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(StoredFindings::class)]
#[UsesClass(RecurringP1Found::class)]
final class RecurringP1sTest extends KernelTestCase
{
    private const string CHANGE = 'recurring-change';
    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

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
        $first = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
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

    // Names a file of the fixture's checkout, so it gets a key.
    private function keyedP1(): Finding
    {
        return new Finding('A', Severity::P1, 'RecordingBus.php', 3, null, 'The bus loses a message.', FixableBy::Builder, false, null, null);
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
