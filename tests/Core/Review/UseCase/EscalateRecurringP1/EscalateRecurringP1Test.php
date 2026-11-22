<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictKept;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Infrastructure\CitedCodeNotShown;
use Intakt\Core\Review\Infrastructure\EscalationAgent;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1Handler;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationBusy;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationCallFailed;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationRequests;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationsOfChange;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\RecordRecurringP1s;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\Vendor\Contract\ProviderRejectedTheKey;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\ScriptedVendor;

#[Group('persistence')]
#[CoversClass(EscalateRecurringP1Handler::class)]
#[CoversClass(EscalateRecurringP1::class)]
#[CoversClass(EscalationRequests::class)]
#[CoversClass(EscalationsOfChange::class)]
#[CoversClass(EscalationBusy::class)]
#[CoversClass(EscalationCallFailed::class)]
#[CoversClass(RecordRecurringP1s::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(EscalationVerdictKept::class)]
#[CoversClass(EscalationReviewFailed::class)]
#[UsesClass(EscalationAgent::class)]
#[UsesClass(EscalationPrompt::class)]
#[UsesClass(CitedCodeNotShown::class)]
#[UsesClass(EscalationReviewed::class)]
#[UsesClass(EscalationFailed::class)]
#[UsesClass(EscalationVerdictReused::class)]
#[UsesClass(EscalationRequest::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(RecurringP1Found::class)]
#[UsesClass(KeptVerdicts::class)]
#[UsesClass(KeptVerdict::class)]
final class EscalateRecurringP1Test extends KernelTestCase
{
    private const string CHANGE = 'escalated-change';
    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string HEAD_C = 'cccccccccccccccccccccccccccccccccccccccc';
    // Changes RecordingBus.php and ScriptedVendor.php, files of the fixture's checkout, so a finding naming either has a key and its code to show.
    private const string DIFF = RoundFixture::DIFF_OF_RECORDING_BUS."diff --git a/ScriptedVendor.php b/ScriptedVendor.php\n@@ -5 +5 @@\n-old\n+new\n";

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testARecurringP1WithoutAKeptVerdictQueuesOneEscalationReviewForThatFindingAlone(): void
    {
        $second = $this->recurredOnASecondHead();

        self::assertEquals([new EscalateRecurringP1($second, 'F1', $this->keyOf('F1'))], $this->fixture->escalations->messages);
    }

    public function testARecurringP1WithAKeptVerdictQueuesNoEscalationReview(): void
    {
        $first = $this->judged($this->completedAt(self::HEAD_A, $this->keyedP1()));
        $this->fixture->keptVerdicts()->keep($this->keyOf('F1'), new EscalationVerdict(EscalationOutcome::Confirmed, 'Seeded.'), $first);

        $this->judged($this->completedAt(self::HEAD_B, $this->repeated($this->keyedP1())));

        self::assertSame([], $this->fixture->escalations->messages);
    }

    public function testTheEscalationReviewIsAskedAboutTheRecurringFindingAloneWithTheRoundsDiff(): void
    {
        $this->judged($this->completedAt(self::HEAD_A, $this->keyedP1(), $this->otherP1()));
        $second = $this->judged($this->completedAt(self::HEAD_B, $this->repeated($this->keyedP1()), $this->repeated($this->otherP1(), 'F2')));
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.'));

        $this->fixture->escalate($this->fixture->escalations->messages[0], $escalations);

        [$project, $round, $messages] = $escalations->calls[0];
        self::assertSame(['alpha', $second, 1], [$project, $round->toRfc4122(), count($escalations->calls)]);
        self::assertStringContainsString('The bus loses a message.', $this->textOf($messages));
        self::assertStringContainsString('+new', $this->textOf($messages));
        self::assertStringNotContainsString('The vendor forgets the model.', $this->textOf($messages));
    }

    public function testAConfirmedEscalationKeepsItsVerdictAndRecordsEscalationReviewedWithOutcomeConfirmed(): void
    {
        $second = $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $this->keyOf('F1'), 'outcome' => 'confirmed', 'reasons' => 'The bus drops it.']], $this->payloadsOf(LedgerEventKind::EscalationReviewed));
        self::assertSame([EscalationOutcome::Confirmed, $second], [$this->keptOf('F1')?->outcome, $this->keptOf('F1')?->round]);
    }

    public function testARejectedEscalationKeepsItsVerdictWithItsReasonsTrimmed(): void
    {
        $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, "  The retry in send() delivers it.\n")));

        self::assertSame([EscalationOutcome::Rejected, 'The retry in send() delivers it.'], [$this->keptOf('F1')?->outcome, $this->keptOf('F1')?->reasons]);
        self::assertSame(['rejected'], array_column($this->payloadsOf(LedgerEventKind::EscalationReviewed), 'outcome'));
    }

    public function testAnAnswerThatIsNoEscalationVerdictKeepsNoVerdictAndRecordsAFailedEscalationWithIntaktsReason(): void
    {
        $second = $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(null));

        self::assertSame([null, []], [$this->keptOf('F1'), $this->payloadsOf(LedgerEventKind::EscalationReviewed)]);
        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $this->keyOf('F1'), 'reason' => EscalationAgent::UNREADABLE]], $this->payloadsOf(LedgerEventKind::EscalationFailed));
    }

    public function testAnEscalationOfAFindingTheRoundDoesNotHoldRecordsAFailedEscalationWithoutAskingAModel(): void
    {
        $second = $this->recurredOnASecondHead();
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->fixture->escalate(new EscalateRecurringP1($second, 'F9', $this->keyOf('F1')), $escalations);

        self::assertSame([[], [EscalationRequests::FINDING_GONE]], [$escalations->calls, array_column($this->payloadsOf(LedgerEventKind::EscalationFailed), 'reason')]);
    }

    public function testAFindingWhoseFileTheDiffCannotHoldWholeRecordsAFailedEscalationWithoutAskingAModel(): void
    {
        $diff = "diff --git a/RecordingBus.php b/RecordingBus.php\n@@ -1 +1 @@\n".str_repeat("+line\n", intdiv(PromptCut::CEILING_BYTES, 6) + 1);
        $this->judged($this->completedWithDiffAt(self::HEAD_A, $diff, $this->keyedP1()));
        $this->judged($this->completedWithDiffAt(self::HEAD_B, $diff, $this->repeated($this->keyedP1())));
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->escalateQueued($escalations);

        self::assertSame([[], null], [$escalations->calls, $this->keptOf('F1')]);
        $reason = $this->payloadsOf(LedgerEventKind::EscalationFailed)[0]['reason'] ?? null;
        self::assertIsString($reason);
        self::assertStringStartsWith('The diff of the files the finding cites holds', $reason);
    }

    public function testAnEscalationOfAKeyAnotherRoundHasEscalatedSinceReusesThatVerdictWithoutAskingAModel(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->fixture->keptVerdicts()->keep($this->keyOf('F1'), new EscalationVerdict(EscalationOutcome::Confirmed, 'Seeded.'), 'other-round');
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Never asked.'));

        $this->escalateQueued($escalations);

        self::assertSame([], $escalations->calls);
        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $this->keyOf('F1'), 'outcome' => 'confirmed', 'verdict_round' => 'other-round']], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));
    }

    public function testALaterRoundOfAnEscalatedKeyReusesTheKeptVerdictAtItsJudgingAndQueuesNoEscalation(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        $third = $this->judged($this->completedAt(self::HEAD_C, $this->repeated($this->keyedP1())));

        self::assertCount(1, $this->fixture->escalations->messages);
        self::assertSame([[$third, $second]], array_map(static fn (array $reused): array => [$reused['round'], $reused['verdict_round']], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused)));
    }

    public function testARedeliveredEscalationAsksNoModelAndRecordsNothingMore(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));
        $again = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Asked twice.'));

        $this->escalateQueued($again);

        self::assertSame([[], 1, [], []], [$again->calls, count($this->payloadsOf(LedgerEventKind::EscalationReviewed)), $this->payloadsOf(LedgerEventKind::EscalationVerdictReused), $this->payloadsOf(LedgerEventKind::EscalationFailed)]);
    }

    public function testARedeliveryAfterAFailedEscalationAsksNoModelAndRecordsOneFailure(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(null));
        $again = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Asked after a failure.'));

        $this->escalateQueued($again);

        self::assertSame([[], null, 1], [$again->calls, $this->keptOf('F1'), count($this->payloadsOf(LedgerEventKind::EscalationFailed))]);
    }

    public function testARedeliveryOfAnEscalationThatReusedAVerdictRecordsTheReuseOnce(): void
    {
        [$second, $third] = $this->bothQueuedAfterAThirdHead();
        $answer = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Already handled.'));
        $this->fixture->escalate($second, $answer);

        $this->fixture->escalate($third, $answer);
        $this->fixture->escalate($third, $answer);

        self::assertSame([1, 1], [count($answer->calls), count($this->payloadsOf(LedgerEventKind::EscalationVerdictReused))]);
    }

    public function testWhenTwoRoundsOfOneKeyRaceTheLoserKeepsNoVerdictAndReusesTheWinners(): void
    {
        [$second, $third] = $this->bothQueuedAfterAThirdHead();
        $key = $this->keyOf('F1');
        $winnerKeepsFirst = fn (): null => $this->fixture->keptVerdicts()->keep($key, new EscalationVerdict(EscalationOutcome::Rejected, 'Kept first.'), $third->round);

        $this->fixture->escalate($second, new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Kept second.'), $winnerKeepsFirst));

        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationReviewed));
        self::assertSame([['round' => $second->round, 'finding' => 'F1', 'key' => $key, 'outcome' => 'rejected', 'verdict_round' => $third->round]], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));
        self::assertSame(['Kept first.', $third->round], [$this->keptOf('F1')?->reasons, $this->keptOf('F1')?->round]);
    }

    public function testAnEscalationDeliveredWhileAnotherOfTheSameKeyRunsIsRetriedLaterAndThenReusesItsVerdict(): void
    {
        [$second, $third] = $this->bothQueuedAfterAThirdHead();
        $overlapping = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Second answer.'));
        $busy = [];
        $first = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'First answer.'), function () use ($third, $overlapping, &$busy): void {
            try {
                $this->fixture->escalate($third, $overlapping);
            } catch (EscalationBusy $refused) {
                $busy[] = $refused;
            }
        });

        $this->fixture->escalate($second, $first);
        $this->fixture->escalate($third, $overlapping);

        self::assertSame([1, 0, 1], [count($first->calls), count($overlapping->calls), count($busy)]);
        self::assertSame([$third->round], array_column($this->payloadsOf(LedgerEventKind::EscalationVerdictReused), 'round'));
    }

    public function testARedeliveryThatRunsAfterTheKeyLockExpiredLeavesTheRoundAndKeyOneOutcome(): void
    {
        $this->recurredOnASecondHead();
        $message = $this->fixture->escalations->messages[0];
        $redelivered = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Inner answer.'));
        $outer = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Outer answer.'), function () use ($message, $redelivered): void {
            $this->fixture->escalate($message, $redelivered, new LockFactory(new InMemoryStore()));
        });

        $this->fixture->escalate($message, $outer);

        self::assertSame(['Inner answer.'], array_column($this->payloadsOf(LedgerEventKind::EscalationReviewed), 'reasons'));
        self::assertSame([[], []], [$this->payloadsOf(LedgerEventKind::EscalationVerdictReused), $this->payloadsOf(LedgerEventKind::EscalationFailed)]);
    }

    public function testAFailureThatARedeliveryRecordedAfterTheKeyLockExpiredIsRecordedOnce(): void
    {
        $this->recurredOnASecondHead();
        $message = $this->fixture->escalations->messages[0];
        $outer = new ScriptedEscalations(null, function () use ($message): void {
            $this->fixture->escalate($message, new ScriptedEscalations(null), new LockFactory(new InMemoryStore()));
        });

        $this->fixture->escalate($message, $outer);

        self::assertCount(1, $this->payloadsOf(LedgerEventKind::EscalationFailed));
    }

    public function testAProviderFailureLeavesTheQueueRetryingWithoutTheProvidersMessageInTheFailedMessage(): void
    {
        $this->recurredOnASecondHead();
        $message = $this->fixture->escalations->messages[0];
        $marker = 'synthetic-private-provider-response';
        $rejecting = new ScriptedEscalations(null, static function () use ($marker): never {
            throw new ProviderRejectedTheKey(new RuntimeException($marker));
        });

        try {
            $this->fixture->escalate($message, $rejecting);
            self::fail('The provider failure did not reach the queue.');
        } catch (EscalationCallFailed $failure) {
            self::assertSame([null, false], [$failure->getPrevious(), str_contains($failure->getMessage(), $marker)]);
            self::assertStringContainsString('the provider rejected the key', $failure->getMessage());
        }

        self::assertSame([], [...$this->payloadsOf(LedgerEventKind::EscalationFailed), ...$this->payloadsOf(LedgerEventKind::EscalationReviewed)]);
        self::assertStringNotContainsString($marker, serialize($this->parkedAfterTheLastRetry($message, $failure)));
        self::assertLoggedError('critical', sprintf('Error thrown while handling message %1$s. Removing from transport after 2 retries. Error: "Handling "%1$s" failed: %2$s"', EscalateRecurringP1::class, $failure->getMessage()));
    }

    public function testTheLedgerEventsShowAConfirmedAndARejectedEscalationAndALaterRoundsReuse(): void
    {
        $this->judged($this->completedAt(self::HEAD_A, $this->keyedP1(), $this->otherP1()));
        $this->judged($this->completedAt(self::HEAD_B, $this->repeated($this->keyedP1()), $this->repeated($this->otherP1(), 'F2')));
        [$bus, $vendor] = $this->fixture->escalations->messages;
        $this->workerAnswers($this->fixture->escalations->messages, new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.'), new EscalationVerdict(EscalationOutcome::Rejected, 'The vendor keeps it.'));
        $this->judged($this->completedAt(self::HEAD_C, $this->repeated($this->keyedP1())));

        $printed = $this->ledgerEvents();

        self::assertSame(1, substr_count($printed, ' escalation_reviewed {"round":"'.$bus->round.'","finding":"F1","key":"'.$bus->key.'","outcome":"confirmed","reasons":"The bus drops it."}'));
        self::assertSame(1, substr_count($printed, ' escalation_reviewed {"round":"'.$vendor->round.'","finding":"F2","key":"'.$vendor->key.'","outcome":"rejected","reasons":"The vendor keeps it."}'));
        self::assertSame(1, substr_count($printed, ' escalation_verdict_reused '));
        self::assertGreaterThan(strrpos($printed, ' escalation_reviewed '), strpos($printed, ' escalation_verdict_reused '));
    }

    /**
     * @return array{EscalateRecurringP1, EscalateRecurringP1}
     */
    private function bothQueuedAfterAThirdHead(): array
    {
        $this->recurredOnASecondHead();
        $this->judged($this->completedAt(self::HEAD_C, $this->repeated($this->keyedP1())));
        [$second, $third] = $this->fixture->escalations->messages;

        return [$second, $third];
    }

    private function recurredOnASecondHead(): string
    {
        $this->judged($this->completedAt(self::HEAD_A, $this->keyedP1()));

        return $this->judged($this->completedAt(self::HEAD_B, $this->repeated($this->keyedP1())));
    }

    private function escalateQueued(ScriptedEscalations $escalations): void
    {
        $this->fixture->escalate($this->fixture->escalations->messages[0], $escalations);
    }

    private function completedAt(string $head, Finding ...$findings): string
    {
        return $this->completedWithDiffAt($head, self::DIFF, ...$findings);
    }

    private function completedWithDiffAt(string $head, string $diff, Finding ...$findings): string
    {
        $round = $this->fixture->startWithDiffAtHead(self::CHANGE, $head, $diff);
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, array_values($findings), [], [])]);

        // Only the reviewers of this round, so the rounds started before are not delivered again.
        foreach ($this->fixture->bus->messages as $message) {
            if ($this->fixture->reviewer($message->reviewerId)->round()->id()->toRfc4122() === $round) {
                $this->fixture->deliver($message, $vendor);
            }
        }

        return $round;
    }

    // An empty answer, so the fallback puts every P1 on the act list whatever ids the round gave it.
    private function judged(string $round): string
    {
        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($round));

        return $round;
    }

    // Names a file of the fixture's checkout, so it gets a key; tied to a requirement, so the goalpost rule keeps it a P1 in later rounds.
    private function keyedP1(): Finding
    {
        return new Finding('A', Severity::P1, 'RecordingBus.php', 3, null, 'The bus loses a message.', FixableBy::Builder, false, 'Every message is delivered.', null);
    }

    private function otherP1(): Finding
    {
        return new Finding('B', Severity::P1, 'ScriptedVendor.php', 5, null, 'The vendor forgets the model.', FixableBy::Builder, false, 'Every model is kept.', null);
    }

    private function repeated(Finding $finding, string $id = 'F1'): Finding
    {
        return new Finding($id, $finding->severity, $finding->file, $finding->line, null, $finding->text, FixableBy::Builder, true, $finding->requirement, null);
    }

    // The user messages only, because they hold what the escalation is shown; the system message is fixed text.
    private function textOf(MessageBag $messages): string
    {
        $users = array_filter($messages->getMessages(), static fn (object $message): bool => $message instanceof UserMessage);

        return implode("\n", array_map(static fn (UserMessage $message): string => (string) $message->asText(), $users));
    }

    private function keyOf(string $finding): string
    {
        $keys = $this->payloadsOf(LedgerEventKind::JudgeDone)[0]['p1_keys'] ?? null;
        self::assertIsArray($keys);
        $key = $keys[$finding] ?? null;
        self::assertIsString($key);

        return $key;
    }

    private function keptOf(string $finding): ?KeptVerdict
    {
        return $this->fixture->keptVerdicts()->ofKey($this->keyOf($finding));
    }

    // The application's handler and command bus behind a worker that reads a Doctrine queue on the test's SQLite database, with only the strong tier scripted, as messenger:consume runs it.
    /**
     * @param list<EscalateRecurringP1> $messages
     */
    private function workerAnswers(array $messages, EscalationVerdict ...$verdicts): void
    {
        $container = self::getContainer();
        $container->set(Vendor::class, new ScriptedVendor());
        $container->set(EscalationReviews::class, new VerdictsInTurn(array_values($verdicts)));
        $queue = new DoctrineTransport(new Connection(['queue_name' => 'escalations'], $container->get('doctrine.dbal.default_connection')), new PhpSerializer());
        foreach ($messages as $message) {
            $queue->send(new Envelope($message));
        }

        new Worker(['async' => $queue], $container->get('command.bus'), $container->get(EventDispatcherInterface::class), $container->get(LoggerInterface::class))->run(['sleep' => 0, 'stop_when_empty' => true]);

        self::assertSame(0, $queue->getMessageCount());
    }

    // Through the application's failure listeners, as a worker hands the message on once its retries are used up.
    private function parkedAfterTheLastRetry(EscalateRecurringP1 $message, EscalationCallFailed $failure): Envelope
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failed);
        $failed->reset();
        $envelope = new Envelope($message, [new RedeliveryStamp(2)]);

        self::getContainer()->get('event_dispatcher')->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [EscalateRecurringP1Handler::class => $failure])));

        $parked = $failed->getSent();
        self::assertCount(1, $parked);

        return $parked[0];
    }

    private function ledgerEvents(): string
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
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
