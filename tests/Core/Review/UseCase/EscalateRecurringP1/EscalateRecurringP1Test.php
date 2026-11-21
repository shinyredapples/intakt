<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationFailed;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationReviewed;
use Intakt\Core\Review\Contract\EscalationStillPending;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\KeptVerdict;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use Intakt\Core\Review\Infrastructure\KeptVerdicts;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1Handler;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationBusy;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationLimited;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationRequests;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationsOfChange;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\HolderReReviews;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\RecordFailedEscalation;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\RecordFailedEscalationHandler;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\RecordRecurringP1s;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\DispatchOnFailureStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[Group('persistence')]
#[CoversClass(EscalateRecurringP1Handler::class)]
#[CoversClass(EscalateRecurringP1::class)]
#[CoversClass(EscalationsOfChange::class)]
#[CoversClass(RecordRecurringP1s::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(EscalationReviewed::class)]
#[UsesClass(EscalationFailed::class)]
#[UsesClass(EscalationVerdictReused::class)]
#[UsesClass(EscalationRequest::class)]
#[UsesClass(EscalationVerdict::class)]
#[UsesClass(RecurringP1Found::class)]
#[UsesClass(KeptVerdicts::class)]
#[UsesClass(KeptVerdict::class)]
#[CoversClass(EscalationRequests::class)]
#[CoversClass(HolderReReviews::class)]
#[CoversClass(EscalationBusy::class)]
#[CoversClass(EscalationLimited::class)]
#[CoversClass(RecordFailedEscalation::class)]
#[CoversClass(RecordFailedEscalationHandler::class)]
#[UsesClass(EscalationPrompt::class)]
#[UsesClass(EscalationStillPending::class)]
#[UsesClass(StartReviewAtHead::class)]
final class EscalateRecurringP1Test extends KernelTestCase
{
    private const string CHANGE = 'escalated-change';
    private const string HEAD_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string HEAD_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string HEAD_C = 'cccccccccccccccccccccccccccccccccccccccc';

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), diff: RoundFixture::DIFF_OF_RECORDING_BUS);
    }

    public function testAQueuedEscalationCarriesTheRecordOfItsFailureAsItsFailureStamp(): void
    {
        $second = $this->recurredOnASecondHead();

        $stamp = $this->fixture->escalations->envelopes[0]->last(DispatchOnFailureStamp::class);

        self::assertEquals(new RecordFailedEscalation($second, 'F1', $this->keyOfFirstRound()), $stamp?->getMessage());
    }

    public function testAProviderThatKeepsFailingTransientlyEndsTheEscalationInOneFailureForTheRoundAndKey(): void
    {
        $second = $this->recurredOnASecondHead();
        $failing = new ScriptedEscalations(null, static fn (): never => throw new ProviderFailedTransiently(new RuntimeException('Overloaded.')));

        $log = new BufferingLogger();

        $limited = $this->limitedBy($failing);
        $this->recordFailure(new RecordFailedEscalation($second, 'F1', $this->keyOfFirstRound()), $log);

        self::assertSame([false, null], [$limited->forceRetry(), $limited->getRetryDelay()]);
        self::assertSame(['error'], array_column($log->cleanLogs(), 0));
        self::assertSame([['round' => $second, 'key' => $this->keyOfFirstRound(), 'reason' => RecordFailedEscalationHandler::PARKED]], array_map(static fn (array $failed): array => ['round' => $failed['round'] ?? null, 'key' => $failed['key'] ?? null, 'reason' => $failed['reason'] ?? null], $this->payloadsOf(LedgerEventKind::EscalationFailed)));
        self::assertNull($this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE));
    }

    public function testAnEscalationTheProviderKeepsFailingIsParkedAfterTheTransportsRetriesAndQueuesTheRecordOfItsFailure(): void
    {
        $this->recurredOnASecondHead();
        $limited = $this->limitedBy(new ScriptedEscalations(null, static fn (): never => throw new ProviderFailedTransiently(new RuntimeException('Overloaded.'))));
        $received = $this->fixture->escalations->envelopes[0]->with(new RedeliveryStamp(2), new ReceivedStamp('async'));
        $failed = new WorkerMessageFailedEvent($received, 'async', new HandlerFailedException($received, [EscalateRecurringP1Handler::class => $limited]));

        self::getContainer()->get('event_dispatcher')->dispatch($failed);

        self::assertFalse($failed->willRetry());
        self::assertLoggedError('critical', sprintf('Error thrown while handling message %s. Removing from transport after 2 retries. Error: "Handling "%s" failed: %s"', EscalateRecurringP1::class, EscalateRecurringP1::class, $limited->getMessage()));
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $records = array_filter(array_map(static fn (Envelope $sent): object => $sent->getMessage(), $async->getSent()), static fn (object $sent): bool => $sent instanceof RecordFailedEscalation);
        self::assertEquals([$this->fixture->escalations->envelopes[0]->last(DispatchOnFailureStamp::class)?->getMessage()], array_values($records));
    }

    public function testAnEscalationTheProviderFailedOnceIsRetriedByTheTransport(): void
    {
        $this->recurredOnASecondHead();
        $limited = $this->limitedBy(new ScriptedEscalations(null, static fn (): never => throw new ProviderFailedTransiently(new RuntimeException('Overloaded.'))));
        $received = $this->fixture->escalations->envelopes[0]->with(new ReceivedStamp('async'));
        $failed = new WorkerMessageFailedEvent($received, 'async', new HandlerFailedException($received, [EscalateRecurringP1Handler::class => $limited]));

        self::getContainer()->get('event_dispatcher')->dispatch($failed);

        self::assertTrue($failed->willRetry());
    }

    public function testARateLimitedEscalationCallComesBackAfterTheProvidersReset(): void
    {
        $this->recurredOnASecondHead();

        $limited = $this->limitedBy(new ScriptedEscalations(null, static fn (): never => throw new ProviderRateLimited(30, new RuntimeException('Too many requests.'))));

        self::assertSame(30000, $limited->getRetryDelay());
    }

    public function testAnEscalationOnABusyVendorAccountComesBackLater(): void
    {
        $this->recurredOnASecondHead();

        $limited = $this->limitedBy(new ScriptedEscalations(null, static fn (): never => throw VendorAccountBusy::at('main')));

        self::assertSame([false, null, []], [$limited->forceRetry(), $limited->getRetryDelay(), $this->payloadsOf(LedgerEventKind::EscalationFailed)]);
    }

    public function testARedeliveredRecordOfAFailedEscalationWritesNoSecondFailure(): void
    {
        $second = $this->recurredOnASecondHead();
        $record = new RecordFailedEscalation($second, 'F1', $this->keyOfFirstRound());

        $this->recordFailure($record);
        $this->recordFailure($record);

        self::assertCount(1, $this->payloadsOf(LedgerEventKind::EscalationFailed));
    }

    public function testARecordOfAFailureForAnEscalationThatKeptItsVerdictWritesNothing(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        $this->recordFailure(new RecordFailedEscalation($second, 'F1', $this->keyOfFirstRound()));

        self::assertSame([[], EscalationOutcome::Confirmed], [$this->payloadsOf(LedgerEventKind::EscalationFailed), $this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE)]);
    }

    public function testAnEscalationDeliveredAfterItsFailureWasRecordedAsksNoModel(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->recordFailure(new RecordFailedEscalation($second, 'F1', $this->keyOfFirstRound()));
        $late = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Too late.'));

        $this->escalateQueued($late);

        self::assertSame([[], null], [$late->requests, $this->keptOfFirstKey()]);
    }

    public function testARecordOfAFailureWhileAnEscalationOfTheKeyRunsComesBackLater(): void
    {
        $second = $this->recurredOnASecondHead();
        $busy = [];
        $running = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.'), function () use ($second, &$busy): void {
            try {
                $this->recordFailure(new RecordFailedEscalation($second, 'F1', $this->keyOfFirstRound()));
            } catch (EscalationBusy $refused) {
                $busy[] = $refused;
            }
        });

        $this->escalateQueued($running);

        self::assertSame([1, []], [count($busy), $this->payloadsOf(LedgerEventKind::EscalationFailed)]);
    }

    public function testAFindingWithoutAFileFieldThatNamesAFileTheDiffDoesNotChangeRecordsAFailedEscalationWithoutAskingAModel(): void
    {
        $finding = new Finding('A', Severity::P1, null, null, null, 'The bus in RecordingBus.php loses a message.', FixableBy::Builder, false, 'Every message is delivered.', null);
        $this->judged($this->completedWithDiffOf(self::HEAD_A, $finding, RoundFixture::DIFF));
        $this->judged($this->completedWithDiffOf(self::HEAD_B, $this->repeated($finding), RoundFixture::DIFF));
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->escalateQueued($escalations);

        self::assertSame([[], EscalationRequests::FINDING_CUT], [$escalations->requests, $this->payloadsOf(LedgerEventKind::EscalationFailed)[0]['reason'] ?? null]);
    }

    public function testAFindingWithoutAFileFieldThatNamesItsFileInItsTextShowsThatFilesHunkAfterALongEarlierHunk(): void
    {
        $finding = new Finding('A', Severity::P1, null, null, null, 'The bus in RecordingBus.php loses a message.', FixableBy::Builder, false, 'Every message is delivered.', null);
        $diff = "diff --git a/src/A.php b/src/A.php\n".str_repeat("+other change\n", 10000)."diff --git a/RecordingBus.php b/RecordingBus.php\n@@ -3 +3 @@\n+the_relevant_change();\n";
        $this->judged($this->completedWithDiffOf(self::HEAD_A, $finding, $diff));
        $this->judged($this->completedWithDiffOf(self::HEAD_B, $this->repeated($finding), $diff));
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.'));

        $this->escalateQueued($escalations);

        self::assertCount(1, $escalations->requests);
        self::assertStringContainsString('+the_relevant_change();', $this->diffShownFor($escalations->requests[0][1]));
    }

    public function testARecurringP1WithoutAKeptVerdictQueuesOneEscalationReviewForThatFindingAlone(): void
    {
        $second = $this->recurredOnASecondHead();

        self::assertEquals([new EscalateRecurringP1($second, 'F1', $this->keyOfFirstRound())], $this->fixture->escalations->messages);
    }

    public function testARecurringP1WithAKeptVerdictQueuesNoEscalationReview(): void
    {
        $first = $this->judged($this->completedAt(self::HEAD_A, $this->keyedP1()));
        $this->fixture->keptVerdicts()->keep($this->keyOfFirstRound(), new EscalationVerdict(EscalationOutcome::Confirmed, 'Seeded.'), $first);

        $this->judged($this->completedAt(self::HEAD_B, $this->repeated($this->keyedP1())));

        self::assertSame([], $this->fixture->escalations->messages);
    }

    public function testTheEscalationReviewIsAskedAboutTheRecurringFindingAloneWithTheRoundsTaskAndDiff(): void
    {
        $second = $this->recurredOnASecondHead();
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.'));

        $this->escalateQueued($escalations);

        [$project, $request] = $escalations->requests[0];
        self::assertSame(['alpha', 1, $second], [$project, count($escalations->requests), $request->round->toRfc4122()]);
        self::assertEquals(new ChangeUnderReview('alpha', RoundFixture::TASK, RoundFixture::DIFF_OF_RECORDING_BUS), $request->change);
        self::assertSame(['F1', Severity::P1, 'The bus loses a message.'], [$request->finding->id, $request->finding->severity, $request->finding->text]);
    }

    public function testAConfirmedEscalationKeepsItsVerdictAndRecordsEscalationReviewedWithOutcomeConfirmed(): void
    {
        $second = $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $this->keyOfFirstRound(), 'outcome' => 'confirmed', 'reasons' => 'The bus drops it.']], $this->payloadsOf(LedgerEventKind::EscalationReviewed));
        self::assertSame([EscalationOutcome::Confirmed, $second], [$this->keptOfFirstKey()?->outcome, $this->keptOfFirstKey()?->round]);
    }

    public function testARejectedEscalationKeepsItsVerdictWithItsReasons(): void
    {
        $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry in send() delivers it.')));

        self::assertSame([EscalationOutcome::Rejected, 'The retry in send() delivers it.'], [$this->keptOfFirstKey()?->outcome, $this->keptOfFirstKey()?->reasons]);
        self::assertSame('rejected', $this->payloadsOf(LedgerEventKind::EscalationReviewed)[0]['outcome'] ?? null);
    }

    public function testAnAnswerThatIsNoEscalationVerdictKeepsNoVerdictAndRecordsAFailedEscalation(): void
    {
        $second = $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(null));

        self::assertNull($this->keptOfFirstKey());
        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationReviewed));
        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $this->keyOfFirstRound(), 'reason' => EscalateRecurringP1Handler::UNREADABLE]], $this->payloadsOf(LedgerEventKind::EscalationFailed));
    }

    public function testAProjectThatMapsNoModelToTheStrongTierRecordsAFailedEscalationWithoutAskingAModel(): void
    {
        $this->recurredOnASecondHead();
        $vendor = self::createStub(Vendor::class);
        $vendor->method('mapsAModelTo')->willReturnCallback(static fn (string $project, Tier $tier): bool => Tier::Strong !== $tier);
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->fixture->escalate($this->fixture->escalations->messages[0], $escalations, $vendor);

        self::assertSame([[], null], [$escalations->requests, $this->keptOfFirstKey()]);
        self::assertSame('project "alpha" maps no model to the "strong" tier, which the escalation review needs', $this->payloadsOf(LedgerEventKind::EscalationFailed)[0]['reason'] ?? null);
    }

    public function testAnEscalationOfAFindingTheRoundDoesNotHoldRecordsAFailedEscalationWithoutAskingAModel(): void
    {
        $second = $this->recurredOnASecondHead();
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->fixture->escalate(new EscalateRecurringP1($second, 'F9', $this->keyOfFirstRound()), $escalations);

        self::assertSame([[], EscalationRequests::FINDING_GONE], [$escalations->requests, $this->payloadsOf(LedgerEventKind::EscalationFailed)[0]['reason'] ?? null]);
    }

    public function testAVerdictKeptByAnotherWriterDuringTheCallIsReusedInsteadOfKeptTwice(): void
    {
        $second = $this->recurredOnASecondHead();
        $key = $this->keyOfFirstRound();
        $keptMeanwhile = fn (): null => $this->fixture->keptVerdicts()->keep($key, new EscalationVerdict(EscalationOutcome::Rejected, 'Kept first.'), 'other-round');

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Kept second.'), $keptMeanwhile));

        self::assertSame([], $this->payloadsOf(LedgerEventKind::EscalationReviewed));
        self::assertSame([['round' => $second, 'finding' => 'F1', 'key' => $key, 'outcome' => 'rejected', 'verdict_round' => 'other-round']], $this->payloadsOf(LedgerEventKind::EscalationVerdictReused));
        self::assertSame('Kept first.', $this->keptOfFirstKey()?->reasons);
    }

    public function testARedeliveredEscalationAsksNoModelAndRecordsNothingMore(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));
        $again = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Asked twice.'));

        $this->escalateQueued($again);

        self::assertSame([[], 1, []], [$again->requests, count($this->payloadsOf(LedgerEventKind::EscalationReviewed)), $this->payloadsOf(LedgerEventKind::EscalationVerdictReused)]);
    }

    public function testAnEscalationOfAKeyAnotherRoundHasEscalatedSinceReusesThatVerdictWithoutAskingAModel(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->fixture->keptVerdicts()->keep($this->keyOfFirstRound(), new EscalationVerdict(EscalationOutcome::Confirmed, 'Seeded.'), 'other-round');
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Never asked.'));

        $this->escalateQueued($escalations);

        self::assertSame([], $escalations->requests);
        self::assertSame([$second], array_column($this->payloadsOf(LedgerEventKind::EscalationVerdictReused), 'round'));
    }

    public function testAfterAConfirmedEscalationTheChangesLatestRoundReadsConfirmedForTheNextBuild(): void
    {
        $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        self::assertSame(EscalationOutcome::Confirmed, $this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE));
    }

    public function testANewerRoundOfTheChangeHasNoEscalationOutcomeUntilItIsEscalated(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);

        self::assertNull($this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE));
    }

    public function testAChangeWithoutRoundsOrEscalationsHasNoEscalationOutcome(): void
    {
        $this->judged($this->completedAt(self::HEAD_A, $this->keyedP1()));

        self::assertSame([null, null], [$this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE), $this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', 'another-change')]);
    }

    public function testAfterARejectedEscalationTheHolderReReviewsTheFindingWithTheEscalationsReasons(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry in send() delivers it.')));

        $next = $this->fixture->round($this->fixture->startAtHead(self::CHANGE, self::HEAD_A));

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $this->fixture->openFindingsOf($next)));
        self::assertEquals(['F1' => new EscalationVerdict(EscalationOutcome::Rejected, 'The retry in send() delivers it.')], $this->fixture->escalationsOfChange()->ofOpenFindings($next));
    }

    public function testAfterAConfirmedEscalationTheRaisingReviewerReReviewsTheNextRoundWithTheConfirmation(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        $next = $this->fixture->round($this->fixture->startAtHead(self::CHANGE, self::HEAD_A));

        self::assertContains('role-1', array_map(static fn ($reviewer): string => $reviewer->roleName(), $next->reviewers()));
        self::assertSame(EscalationOutcome::Confirmed, $this->fixture->escalationsOfChange()->ofOpenFindings($next)['F1']->outcome ?? null);
    }

    public function testAResolvedFindingCarriesNoEscalationIntoTheNextRound(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Fine as it is.')));
        $third = $this->fixture->startAtHead(self::CHANGE, self::HEAD_A);
        $this->deliverAll($third, new Verdict(Decision::Approve, [], ['F1'], []));
        $this->judged($third);

        $fourth = $this->fixture->round($this->fixture->startAtHead(self::CHANGE, self::HEAD_B));

        self::assertNotSame($second, $third);
        self::assertSame([], $this->fixture->escalationsOfChange()->ofOpenFindings($fourth));
    }

    public function testTheLedgerEventsPrintTheRecurrenceAndThenItsEscalationReviewedWithTheOutcome(): void
    {
        $second = $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        $printed = $this->ledgerEvents();

        self::assertStringContainsString(' escalation_reviewed {"round":"'.$second.'","finding":"F1","key":"'.$this->keyOfFirstRound().'","outcome":"confirmed","reasons":"The bus drops it."}', $printed);
        self::assertGreaterThan(strpos($printed, ' recurring_p1_found '), strpos($printed, ' escalation_reviewed '));
    }

    public function testWhileTheEscalationIsQueuedTheChangeHasNoOutcomeYetSoNoBuildTierIsChosen(): void
    {
        $this->recurredOnASecondHead();

        $this->expectException(EscalationStillPending::class);

        $this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE);
    }

    public function testAFailedEscalationLeavesTheChangeWithoutAnOutcomeAndNoLongerPending(): void
    {
        $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(null));

        self::assertNull($this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE));
    }

    public function testAnotherRoundOnTheConfirmedHeadKeepsTheConfirmationForTheNextBuild(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        $this->fixture->startAtHead(self::CHANGE, self::HEAD_B);

        self::assertSame(EscalationOutcome::Confirmed, $this->fixture->escalationsOfChange()->outcomeAtLatestHead('alpha', self::CHANGE));
    }

    public function testAnUnreadableAnswerIsRecordedAsOneFailureAcrossRedeliveries(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(null));
        $again = new ScriptedEscalations(null);

        $this->escalateQueued($again);

        self::assertSame([[], null, 1], [$again->requests, $this->keptOfFirstKey(), count($this->payloadsOf(LedgerEventKind::EscalationFailed))]);
    }

    public function testARedeliveryOfAnEscalationThatReusedAVerdictRecordsTheReuseOnce(): void
    {
        $this->recurredOnAThirdHead();
        [$second, $third] = $this->fixture->escalations->messages;
        $answer = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Already handled.'));
        $this->fixture->escalate($second, $answer);

        $this->fixture->escalate($third, $answer);
        $this->fixture->escalate($third, $answer);

        self::assertSame([1, 1], [count($answer->requests), count($this->payloadsOf(LedgerEventKind::EscalationVerdictReused))]);
    }

    public function testAnEscalationDeliveredWhileAnotherOfTheSameKeyRunsIsRetriedLaterAndThenReusesItsVerdict(): void
    {
        $this->recurredOnAThirdHead();
        [$second, $third] = $this->fixture->escalations->messages;
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

        self::assertSame([1, 0, 1], [count($first->requests), count($overlapping->requests), count($busy)]);
        self::assertSame(['confirmed'], array_column($this->payloadsOf(LedgerEventKind::EscalationVerdictReused), 'outcome'));
    }

    public function testARejectionStartsTheNextRoundOfTheChangeOnTheRejectedHeadFromItsRepository(): void
    {
        $second = $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry in send() delivers it.')));

        self::assertEquals([new StartReviewAtHead(new StartReview('alpha', RoundFixture::TASK, new RequestedRound(self::CHANGE, 3, new ChangeSource('/repos/alpha', 'main'))), new CommitId(self::HEAD_B))], $this->fixture->reviewStarts->starts);
        self::assertSame(2, $this->fixture->round($second)->number());
    }

    public function testAConfirmationStartsNoRoundOfItsOwn(): void
    {
        $this->recurredOnASecondHead();

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops it.')));

        self::assertSame([], $this->fixture->reviewStarts->starts);
    }

    public function testARedeliveredRejectionStartsTheHoldersReReviewOnce(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'The retry in send() delivers it.')));

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Asked twice.')));

        self::assertCount(1, $this->fixture->reviewStarts->starts);
    }

    public function testARejectionInARoundWithoutARepositoryStartsNoRound(): void
    {
        $this->judged($this->completedWithoutRepositoryAt(self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedWithoutRepositoryAt(self::HEAD_B, $this->repeated($this->keyedP1())));

        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Fine as it is.')));

        self::assertSame([], $this->fixture->reviewStarts->starts);
    }

    public function testAReusedRejectionAtTheJudgingQueuesItsMessageSoTheHolderReReviewsTheNewHead(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Fine as it is.')));
        $third = $this->judged($this->completedAt(self::HEAD_C, $this->repeated($this->keyedP1())));
        $never = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->fixture->escalate($this->latestQueued(), $never);

        self::assertSame([$third, []], [$this->latestQueued()->round, $never->requests]);
        self::assertSame([3, 4], array_map(static fn (StartReviewAtHead $start): ?int => $start->start->round->number, $this->fixture->reviewStarts->starts));
    }

    public function testAHolderThatHoldsARejectedFindingAgainOnItsReReviewGetsNoFurtherRoundOnThatHead(): void
    {
        $this->recurredOnASecondHead();
        $this->escalateQueued(new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Rejected, 'Fine as it is.')));
        $this->judged($this->completedAt(self::HEAD_B, $this->repeated($this->keyedP1())));

        $this->fixture->escalate($this->latestQueued(), new ScriptedEscalations(null));

        self::assertCount(1, $this->fixture->reviewStarts->starts);
    }

    public function testAFindingWhoseFileTheDiffCannotHoldWholeRecordsAFailedEscalationWithoutAskingAModel(): void
    {
        $this->judged($this->completedWithDiffAt(self::HEAD_A, $this->keyedP1()));
        $this->judged($this->completedWithDiffAt(self::HEAD_B, $this->repeated($this->keyedP1())));
        $escalations = new ScriptedEscalations(new EscalationVerdict(EscalationOutcome::Confirmed, 'Never asked.'));

        $this->escalateQueued($escalations);

        self::assertSame([[], EscalationRequests::FINDING_CUT], [$escalations->requests, $this->payloadsOf(LedgerEventKind::EscalationFailed)[0]['reason'] ?? null]);
    }

    private function latestQueued(): EscalateRecurringP1
    {
        $latest = array_last($this->fixture->escalations->messages);
        self::assertNotNull($latest);

        return $latest;
    }

    private function recurredOnAThirdHead(): string
    {
        $this->recurredOnASecondHead();

        return $this->judged($this->completedAt(self::HEAD_C, $this->repeated($this->keyedP1())));
    }

    private function completedWithoutRepositoryAt(string $head, Finding $finding): string
    {
        $round = $this->fixture->startAtHead(self::CHANGE, $head);
        $this->deliverAll($round, new Verdict(Decision::Changes, [$finding], [], []));

        return $round;
    }

    // The finding's own file is longer than the prompt holds, so the cut would reach into it.
    private function completedWithDiffAt(string $head, Finding $finding): string
    {
        $diff = "diff --git a/RecordingBus.php b/RecordingBus.php\n@@ -1 +1 @@\n".str_repeat("+line\n", intdiv(PromptCut::CEILING_BYTES, 6) + 1);
        $round = $this->fixture->startWithDiffAtHead(self::CHANGE, $head, $diff);
        $this->deliverAll($round, new Verdict(Decision::Changes, [$finding], [], []));

        return $round;
    }

    private function completedWithDiffOf(string $head, Finding $finding, string $diff): string
    {
        $round = $this->fixture->startWithDiffAtHead(self::CHANGE, $head, $diff);
        $this->deliverAll($round, new Verdict(Decision::Changes, [$finding], [], []));

        return $round;
    }

    // The prompt the strong tier gets for the request, because the scripted strong tier sees the request before the prompt orders its diff.
    private function diffShownFor(EscalationRequest $request): string
    {
        $message = new EscalationPrompt(new PromptCut(new Fence()))->messagesFor($request)->getMessages()[2];
        self::assertInstanceOf(UserMessage::class, $message);
        $text = $message->asText();
        self::assertIsString($text);

        return $text;
    }

    private function recordFailure(RecordFailedEscalation $record, LoggerInterface $logger = new NullLogger()): void
    {
        $this->fixture->failedEscalationRecorder($logger)($record);
    }

    private function limitedBy(ScriptedEscalations $escalations): EscalationLimited
    {
        try {
            $this->escalateQueued($escalations);
        } catch (EscalationLimited $limited) {
            return $limited;
        }

        self::fail('The escalation call was not limited.');
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

    private function completedAt(string $head, Finding $finding): string
    {
        $round = $this->fixture->startAtHeadOfRepository(self::CHANGE, $head);
        $this->deliverAll($round, new Verdict(Decision::Changes, [$finding], [], []));

        return $round;
    }

    // Only the reviewers of this round, so the rounds started before are not delivered again.
    private function deliverAll(string $round, Verdict $verdict): void
    {
        $vendor = new VerdictPerRole(['Look at part 1.' => $verdict]);

        foreach ($this->fixture->bus->messages as $message) {
            if ($this->fixture->reviewer($message->reviewerId)->round()->id()->toRfc4122() === $round) {
                $this->fixture->deliver($message, $vendor);
            }
        }
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

    private function repeated(Finding $finding): Finding
    {
        return new Finding('F1', $finding->severity, $finding->file, $finding->line, null, $finding->text, FixableBy::Builder, true, $finding->requirement, null);
    }

    private function keyOfFirstRound(): string
    {
        $keys = $this->payloadsOf(LedgerEventKind::JudgeDone)[0]['p1_keys'] ?? null;
        self::assertIsArray($keys);
        $key = array_first($keys);
        self::assertIsString($key);

        return $key;
    }

    private function keptOfFirstKey(): ?KeptVerdict
    {
        return $this->fixture->keptVerdicts()->ofKey($this->keyOfFirstRound());
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
