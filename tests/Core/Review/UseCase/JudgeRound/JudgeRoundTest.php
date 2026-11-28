<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentialRedactor;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\CausesInOneHunk;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\NearestChangedLines;
use Intakt\Core\Review\Domain\QuotesInChange;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Domain\RoundTransition;
use Intakt\Core\Review\Infrastructure\AddedLinesInCheckout;
use Intakt\Core\Review\Infrastructure\ChangeText;
use Intakt\Core\Review\Infrastructure\CheckedOutFile;
use Intakt\Core\Review\Infrastructure\DiffAddedLines;
use Intakt\Core\Review\Infrastructure\DiffHunks;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeLimited;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\JudgeRound\PublishJudgedRound;
use Intakt\Core\Review\UseCase\JudgeRound\QueueJudgingOfCompletedRound;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Throwable;

#[Group('persistence')]
#[CoversClass(JudgeRoundHandler::class)]
#[CoversClass(PublishJudgedRound::class)]
#[UsesClass(LiveRoundProgress::class)]
#[CoversClass(JudgeRound::class)]
#[CoversClass(JudgedRounds::class)]
#[CoversClass(JudgeLimited::class)]
#[CoversClass(QueueJudgingOfCompletedRound::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(StoredFindings::class)]
#[CoversClass(FindingLedger::class)]
#[CoversClass(RoundTransition::class)]
#[CoversClass(QuotesInChange::class)]
#[CoversClass(ChangeText::class)]
#[UsesClass(DiffPaths::class)]
#[UsesClass(Paths::class)]
#[CoversClass(CausesInOneHunk::class)]
#[CoversClass(DiffHunks::class)]
#[UsesClass(JudgedFindings::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(NearestChangedLines::class)]
#[UsesClass(DiffAddedLines::class)]
#[UsesClass(AddedLinesInCheckout::class)]
#[UsesClass(CheckedOutFile::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(QuoteNearLines::class)]
#[UsesClass(NearLine::class)]
#[UsesClass(RoundJudged::class)]
#[UsesClass(FollowUp::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(HeldCredentialRedactor::class)]
#[UsesClass(HeldCredentials::class)]
#[UsesClass(Redaction::class)]
final class JudgeRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;
    private ?TemporaryRepository $checkout = null;

    public function testACompletedRoundIsQueuedForJudgingOnce(): void
    {
        $round = $this->completedRound();

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertEquals([new JudgeRound($round->id()->toRfc4122())], $this->fixture->judgings());
    }

    public function testARoundStillCollectingIsNotQueuedForJudging(): void
    {
        $this->fixture->start(2);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([], $this->fixture->judgings());
    }

    public function testJudgingARoundRecordsJudgeDoneWithTheActIdsAndTheFollowUpIds(): void
    {
        $round = $this->completedRound();
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act), new JudgedFinding('F3', Mark::Defer)]);

        $this->fixture->judge(new ScriptedJudgement($judgement))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1', 'F2'], 'follow_ups' => ['F3']]], $this->judgeCalls());
        self::assertFalse($this->judgeDonePayloads()[0]['fallback']);
    }

    public function testJudgeDoneRecordsEachReviewersVoteAsTheQuorumCountsIt(): void
    {
        $round = $this->roundReporting([$this->finding('A', Severity::P3)]);
        $reviewer = $round->reviewers()[0]->id()->toRfc4122();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Defer)])))($this->judgingOf($round));

        self::assertSame([$reviewer => 'approve'], $this->judgeDonePayloads()[0]['votes']);
    }

    public function testJudgeDoneRecordsANullKeyForAP1NamingNoFileAndNoneForAP2(): void
    {
        $round = $this->roundReporting([$this->finding('A', Severity::P1), $this->finding('B', Severity::P2)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(['F1' => null], $this->judgeDonePayloads()[0]['p1_keys']);
    }

    public function testJudgeDoneRecordsAChangesVoteForAP3TheJudgeMarksAct(): void
    {
        $round = $this->roundReporting([$this->finding('A', Severity::P3)]);
        $reviewer = $round->reviewers()[0]->id()->toRfc4122();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)])))($this->judgingOf($round));

        self::assertSame([$reviewer => 'changes'], $this->judgeDonePayloads()[0]['votes']);
    }

    public function testAGateGapP3TheJudgeMarksActIsRecordedAsAFollowUpAndNotOnTheActList(): void
    {
        $gateGap = new Finding('A', Severity::P3, null, null, null, 'The cs gate misses this case.', FixableBy::Builder, false, 'cs', null, gate_gap: true);
        $round = $this->roundReporting([$gateGap, $this->finding('B', Severity::P3)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act)])))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F2'], 'follow_ups' => ['F1']]], $this->judgeCalls());
    }

    public function testAReviewerWhoseOnlyFindingIsAGateGapP3TheJudgeMarksActVotesApprove(): void
    {
        $round = $this->roundReporting([new Finding('A', Severity::P3, null, null, null, 'The cs gate misses this case.', FixableBy::Builder, false, 'cs', null, gate_gap: true)]);
        $reviewer = $round->reviewers()[0]->id()->toRfc4122();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)])))($this->judgingOf($round));

        self::assertSame([$reviewer => 'approve'], $this->judgeDonePayloads()[0]['votes']);
    }

    public function testAGateGapP1TheJudgeMarksActStaysOnTheActListAndItsReviewerVotesChanges(): void
    {
        $round = $this->roundReporting([new Finding('A', Severity::P1, null, null, null, 'The cs gate misses this case.', FixableBy::Builder, false, null, null, gate_gap: true)]);
        $reviewer = $round->reviewers()[0]->id()->toRfc4122();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)])))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1'], 'follow_ups' => []]], $this->judgeCalls());
        self::assertSame([$reviewer => 'changes'], $this->judgeDonePayloads()[0]['votes']);
    }

    public function testAJudgeThatRejectsAP1AndDefersAP2LeavesBothOnTheActList(): void
    {
        $round = $this->completedRound();
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Reject), new JudgedFinding('F2', Mark::Defer), new JudgedFinding('F3', Mark::Reject)]);

        $this->fixture->judge(new ScriptedJudgement($judgement))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1', 'F2'], 'follow_ups' => []]], $this->judgeCalls());
    }

    public function testJudgeDoneHoldsEveryJudgedFindingWithItsLedgerIdSeverityAndText(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(
            [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of A.', 'merged' => [], 'file' => null, 'line' => null, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null], ['id' => 'F2', 'severity' => 'P2', 'text' => 'Text of B.', 'merged' => [], 'file' => null, 'line' => null, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null], ['id' => 'F3', 'severity' => 'P3', 'text' => 'Text of C.', 'merged' => [], 'file' => null, 'line' => null, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null]],
            $this->judgeDonePayloads()[0]['findings'],
        );
    }

    public function testAJudgeAnswerThatRejectsAP1KeepsItsNoteWithTheStoredFinding(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Reject, null, 'The branch is unreachable.')])))($this->judgingOf($round));

        $payload = $this->judgeDonePayloads()[0];
        self::assertSame(['F1', 'F2'], $payload['act']);
        self::assertSame(['F1' => 'The branch is unreachable.', 'F2' => null, 'F3' => null], $this->notesOf($payload));
    }

    public function testAJudgeAnswerThatDefersAP2KeepsItsNoteWithTheStoredFinding(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer, null, 'Needs its own design.')])))($this->judgingOf($round));

        $payload = $this->judgeDonePayloads()[0];
        self::assertSame(['F1', 'F2'], $payload['act']);
        self::assertSame(['F1' => null, 'F2' => 'Needs its own design.', 'F3' => null], $this->notesOf($payload));
    }

    public function testAP1TheJudgeDoesNotAnswerIsStoredWithoutANote(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F2', Mark::Reject, null, 'Not a bug.')])))($this->judgingOf($round));

        self::assertNull($this->notesOf($this->judgeDonePayloads()[0])['F1']);
    }

    public function testAJudgesNoteOfOnlyWhitespaceIsStoredAsNoNote(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Reject, null, " \n ")])))($this->judgingOf($round));

        self::assertNull($this->notesOf($this->judgeDonePayloads()[0])['F1']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notesQuotingAHeldCredential(): iterable
    {
        yield 'at the start' => ["probe-judge-secret-value is in the diff.\n", '[redacted] is in the diff.'];
        yield 'in the middle' => ['The diff quotes probe-judge-secret-value in a test.', 'The diff quotes [redacted] in a test.'];
        yield 'at the end' => [' The diff quotes probe-judge-secret-value', 'The diff quotes [redacted]'];
    }

    #[DataProvider('notesQuotingAHeldCredential')]
    public function testAHeldCredentialInAJudgesNoteIsStoredRedactedAndTrimmed(string $note, string $stored): void
    {
        $round = $this->completedRound();
        $previous = $_SERVER['APP_SECRET'] ?? null;
        $_SERVER['APP_SECRET'] = " probe-judge-secret-value\n";

        try {
            $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Reject, null, $note)])))($this->judgingOf($round));
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previous;
            }
        }

        self::assertSame($stored, $this->notesOf($this->judgeDonePayloads()[0])['F1']);
    }

    public function testJudgingARoundMovesItToJudged(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(RoundState::Judged, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAReviewerThatFinishesPublishesHowManyOfTheRoundAreIn(): void
    {
        $round = $this->fixture->start(2);

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([sprintf('round %s 0/2', $round->id()->toRfc4122()), sprintf('round %s 1/2', $round->id()->toRfc4122())], $this->fixture->updates->seen);
    }

    public function testAfterTheJudgesCallEveryReviewerCountsAsStartedAndFinished(): void
    {
        $round = $this->completedRound();
        $this->fixture->updates->progress = [];

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        $last = array_last($this->fixture->updates->progress);
        self::assertInstanceOf(RoundProgress::class, $last);
        self::assertSame([2, 2, 2], [$last->reviewersStarted, $last->reviewersFinished, $last->reviewers]);
    }

    public function testAJudgedRoundPublishesItsFinalState(): void
    {
        $round = $this->completedRound();
        $this->fixture->updates->seen = [];

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame([sprintf('round %s 2/2 judged', $round->id()->toRfc4122())], $this->fixture->updates->seen);
    }

    public function testAReviewerMessageDeliveredAgainAfterTheJudgeStillShowsTheRoundJudged(): void
    {
        $round = $this->completedRound();
        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));
        $this->fixture->updates->seen = [];

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([sprintf('round %s 2/2 judged', $round->id()->toRfc4122())], $this->fixture->updates->seen);
    }

    public function testAJudgedRoundAnnouncesTheCheckoutItsReviewersRead(): void
    {
        $round = $this->completedRound();
        $announced = [];
        $this->fixture->events->addListener(RoundJudged::class, static function (RoundJudged $judged) use (&$announced): void {
            $announced[] = $judged;
        });

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertEquals([new RoundJudged($round->id()->toRfc4122(), $round->checkoutRoot())], $announced);
    }

    public function testARoundJudgedAlreadyIsNotAnnouncedAgain(): void
    {
        $round = $this->completedRound();
        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));
        $announced = 0;
        $this->fixture->events->addListener(RoundJudged::class, static function () use (&$announced): void {
            ++$announced;
        });

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(0, $announced);
    }

    public function testTheJudgeIsAskedAboutTheFindingsOfTheRoundUnderTheLedgerIdsWithItsTask(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($vendor)($this->judgingOf($round));

        $asked = $vendor->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame(RoundFixture::TASK, $asked->task);
        self::assertSame([['F1', 'Text of A.'], ['F2', 'Text of B.'], ['F3', 'Text of C.']], $this->idsAndTexts($asked));
    }

    public function testTheJudgeIsGivenTheFilesOfTheRoundsDiff(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($vendor)($this->judgingOf($round));

        $asked = $vendor->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame(['src/Thing.php'], [...$asked->changedFiles]);
    }

    public function testTwoReviewersReportingDifferentFindingsUnderOneIdBothReachTheJudge(): void
    {
        $round = $this->fixture->start(2);
        $vendor = new VerdictPerRole([
            'Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], []),
            'Look at part 2.' => new Verdict(Decision::Changes, [new Finding('A', Severity::P1, null, null, null, 'Another defect.', FixableBy::Builder, false, null, null)], [], []),
        ]);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($round));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F1', 'Text of A.'], ['F2', 'Another defect.']], $this->idsAndTexts($asked));
    }

    public function testAnOlderRoundCompletingAfterANewerOneStillSendsItsFindingsToTheJudge(): void
    {
        $older = $this->fixture->start(1);
        $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole([]));
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($older));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['R1-F1', 'Text of A.']], $this->idsAndTexts($asked));
    }

    public function testANewFindingOfAnOlderRoundCompletingAfterANewerOneKeepsAnIdNoLaterRoundIsGiven(): void
    {
        $older = $this->fixture->start(1);
        $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('B', Severity::P1)], [], [])]));
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));
        $later = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[2], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('C', Severity::P1)], [], [])]));

        self::assertSame([['F2', 'Text of C.']], $this->askedAbout($later));
        self::assertSame([['R1-F2', 'Text of A.']], $this->askedAbout($older));
    }

    public function testARoundThatReportedNoFindingsIsJudgedWithoutAskingTheJudge(): void
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));
        $vendor = new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)]));

        $this->fixture->judge($vendor)($this->judgingOf($round));

        self::assertSame(0, $vendor->calls);
        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => [], 'follow_ups' => []]], $this->judgeCalls());
        self::assertSame(RoundState::Judged, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAFindingALaterRoundReportsAgainStillReachesTheJudgeOfTheRoundThatReportedItFirst(): void
    {
        $first = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));
        $this->fixture->start(1);
        $repeat = new Finding('F1', Severity::P1, null, null, null, 'Text of A, again.', FixableBy::Builder, true, null, null);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$repeat], [], [])]));
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($first));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F1', 'Text of A.']], $this->idsAndTexts($asked));
    }

    // Decision 5 of #2981: the dashboard leaves such a round out of the judge's chart, because its unmarked P3 is no rejection of the judge's.
    public function testAnInvalidJudgeAnswerIsRecordedAsTheFallback(): void
    {
        $fallback = $this->completedRound();
        $this->fixture->judge(new ScriptedJudgement(Judgement::everyBlockingFindingActs([$this->finding('F1', Severity::P1), $this->finding('F2', Severity::P2), $this->finding('F3', Severity::P3)])))($this->judgingOf($fallback));

        self::assertTrue($this->judgeDonePayloads()[0]['fallback']);
        self::assertSame([['round' => $fallback->id()->toRfc4122(), 'act' => ['F1', 'F2'], 'follow_ups' => []]], $this->judgeCalls());
    }

    // The fallback marks every finding when none is a P3, so only the place that chooses it can tell; the marks cannot (PR #2989, second review round).
    public function testAFallbackThatMarksEveryFindingIsStillRecordedAsTheFallback(): void
    {
        $fallback = $this->completedRound();
        $this->fixture->judge(new ScriptedJudgement(Judgement::everyBlockingFindingActs([$this->finding('F1', Severity::P1), $this->finding('F2', Severity::P1), $this->finding('F3', Severity::P1)])))($this->judgingOf($fallback));

        self::assertTrue($this->judgeDonePayloads()[0]['fallback']);
    }

    public function testAJudgedRoundDeliveredAgainAsksTheJudgeNothingAndRecordsNoSecondJudgeDone(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)]));
        $handler = $this->fixture->judge($vendor);

        $handler($this->judgingOf($round));
        $handler($this->judgingOf($round));

        self::assertSame(1, $vendor->calls);
        self::assertCount(1, $this->judgeDonePayloads());
    }

    public function testARoundJudgedByAnotherWorkerDuringTheCallKeepsOnlyThatWorkersJudgeDone(): void
    {
        $round = $this->completedRound();
        $first = new Judgement([new JudgedFinding('F1', Mark::Act)]);
        $second = new Judgement([new JudgedFinding('F1', Mark::Defer)]);
        $otherWorker = fn () => $this->fixture->judge(new ScriptedJudgement($first))($this->judgingOf($round));

        $this->fixture->judge(new ScriptedJudgement($second, $otherWorker))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1', 'F2'], 'follow_ups' => []]], $this->judgeCalls());
    }

    public function testAFindingQuotingANameAbsentFromTheChangeNeverReachesTheJudge(): void
    {
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`grepName` is not defined.'), $this->quoting('B', Severity::P2, '`old` is still read.')]);
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($round));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F2', '`old` is still read.']], $this->idsAndTexts($asked));
        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F2'], 'follow_ups' => []]], $this->judgeCalls());
    }

    public function testAFindingWithoutAQuoteAndASymbolAbsentFromTheChangeReachesTheJudge(): void
    {
        $finding = new Finding('A', Severity::P1, 'src/Thing.php', 1, 'grepName', 'The command is built from user input.', FixableBy::Builder, false, null, null);
        $round = $this->roundReporting([$finding]);
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($round));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F1', 'The command is built from user input.']], $this->idsAndTexts($asked));
    }

    public function testAFindingQuotingANameAbsentFromTheChangeIsStoredWithTheRoundAsDroppedWithItsReason(): void
    {
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`grepName` is not defined.'), $this->quoting('B', Severity::P2, '`old` is still read.')]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(
            [['id' => 'F1', 'severity' => 'P1', 'text' => '`grepName` is not defined.', 'file' => 'src/Thing.php', 'line' => 1, 'reason' => 'none of its 1 quoted code fragments is in the change', 'nearest' => [], 'gate_gap' => false]],
            $this->judgeDonePayloads()[0]['dropped'],
        );
    }

    public function testAHeldCredentialOnANearestChangedLineIsRecordedRedacted(): void
    {
        $this->checkOutThingHolding("\$thing = new Thing();\n");
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`newThing` is not defined.')]);
        $previous = $_SERVER['APP_SECRET'] ?? null;
        $_SERVER['APP_SECRET'] = 'new';

        try {
            $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previous;
            }
        }

        self::assertSame(
            [['id' => 'F1', 'severity' => 'P1', 'text' => '`newThing` is not defined.', 'file' => 'src/Thing.php', 'line' => 1, 'reason' => 'none of its 1 quoted code fragments is in the change', 'nearest' => [['quote' => '[redacted]Thing', 'lines' => [['file' => 'src/Thing.php', 'line' => 1, 'text' => '$thing = [redacted] Thing();']]]], 'gate_gap' => false]],
            $this->judgeDonePayloads()[0]['dropped'],
        );
    }

    public function testAFindingDroppedForItsQuotesIsRecordedWithTheNearestChangedLines(): void
    {
        $this->checkOutThingHolding("new\n");
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`newThing` is not defined.')]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(
            [['id' => 'F1', 'severity' => 'P1', 'text' => '`newThing` is not defined.', 'file' => 'src/Thing.php', 'line' => 1, 'reason' => 'none of its 1 quoted code fragments is in the change', 'nearest' => [['quote' => 'newThing', 'lines' => [['file' => 'src/Thing.php', 'line' => 1, 'text' => 'new']]]], 'gate_gap' => false]],
            $this->judgeDonePayloads()[0]['dropped'],
        );
    }

    public function testAFindingWithoutAQuoteAndASymbolAbsentFromTheChangeIsNotStoredAsDropped(): void
    {
        $round = $this->roundReporting([new Finding('A', Severity::P1, 'src/Thing.php', 1, 'grepName', 'The command is built from user input.', FixableBy::Builder, false, null, null)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame([], $this->judgeDonePayloads()[0]['dropped']);
        self::assertSame(['F1'], $this->judgeDonePayloads()[0]['act']);
    }

    public function testADroppedFindingIsNeverMergedWithARealOneAtItsPlace(): void
    {
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`grepName` is not defined.'), $this->quoting('B', Severity::P2, '`old` is still read.')]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame([['id' => 'F2', 'severity' => 'P2', 'text' => '`old` is still read.', 'merged' => [], 'file' => 'src/Thing.php', 'line' => 1, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null]], $this->judgeDonePayloads()[0]['findings']);
    }

    public function testTwoFindingsTheJudgeGivesOneCauseAreRecordedAsOneEntryWithTheHigherSeverityAndTheLink(): void
    {
        $round = $this->roundReporting([$this->atLine('A', Severity::P2, 3), $this->atLine('B', Severity::P1, 5)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act, 'F1')])))($this->judgingOf($round));

        $payload = $this->judgeDonePayloads()[0];
        self::assertSame(['F2' => 'F1'], $payload['same_cause']);
        self::assertSame([['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of A.', 'merged' => ['F2'], 'file' => 'src/Thing.php', 'line' => 3, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null], ['id' => 'F2', 'severity' => 'P1', 'text' => 'Text of B.', 'merged' => [], 'file' => 'src/Thing.php', 'line' => 5, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null]], $payload['findings']);
    }

    public function testTwoFindingsInOneHunkWithDifferentCausesAreRecordedAsTwoEntries(): void
    {
        $round = $this->roundReporting([$this->atLine('A', Severity::P2, 3), $this->atLine('B', Severity::P1, 5)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act)])))($this->judgingOf($round));

        $payload = $this->judgeDonePayloads()[0];
        self::assertSame([], $payload['same_cause']);
        self::assertSame($this->twoSeparateEntries(5), $payload['findings']);
    }

    public function testALinkBetweenFindingsOutsideOneHunkOfTheChangeIsNotRecorded(): void
    {
        $round = $this->roundReporting([$this->atLine('A', Severity::P2, 3), $this->atLine('B', Severity::P1, 12)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act, 'F1')])))($this->judgingOf($round));

        $payload = $this->judgeDonePayloads()[0];
        self::assertSame([], $payload['same_cause']);
        self::assertSame($this->twoSeparateEntries(12), $payload['findings']);
    }

    public function testTheLinkThatWouldStretchAChainFurtherThanFiveLinesIsNotAppliedToTheRecordedEntries(): void
    {
        $round = $this->roundReporting([$this->atLine('A', Severity::P2, 1), $this->atLine('B', Severity::P2, 5), $this->atLine('C', Severity::P2, 9)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act, 'F1'), new JudgedFinding('F3', Mark::Act, 'F2')])))($this->judgingOf($round));

        $findings = $this->judgeDonePayloads()[0]['findings'];
        self::assertIsArray($findings);
        self::assertSame([['F1', 'F2', 'F3'], ['Text of A.', 'Text of B.', 'Text of C.'], [['F2'], [], []]], [array_column($findings, 'id'), array_column($findings, 'text'), array_column($findings, 'merged')]);
    }

    public function testALinkToAFindingDroppedBeforeTheJudgeIsNotRecorded(): void
    {
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`grepName` is not defined.'), $this->atLine('B', Severity::P2, 3)]);

        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F2', Mark::Act, 'F1')])))($this->judgingOf($round));

        self::assertSame([], $this->judgeDonePayloads()[0]['same_cause']);
    }

    public function testARoundWhoseEveryFindingIsDroppedIsJudgedWithoutAskingTheJudge(): void
    {
        $round = $this->roundReporting([$this->quoting('A', Severity::P1, '`grepName` is not defined.')]);
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($round));

        self::assertSame(0, $judge->calls);
        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => [], 'follow_ups' => []]], $this->judgeCalls());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function limitedCalls(): iterable
    {
        yield 'busy account' => [VendorAccountBusy::at('anthropic')];
        yield 'rate limit' => [new ProviderRateLimited(30, new RuntimeException('limited'))];
        yield 'transient failure' => [new ProviderFailedTransiently(new RuntimeException('down'))];
        yield 'account out' => [new VendorAccountOut(new RuntimeException('out'))];
    }

    #[DataProvider('limitedCalls')]
    public function testALimitedJudgeCallIsRetriedAndRecordsNothing(Throwable $limit): void
    {
        $round = $this->completedRound();

        try {
            $this->fixture->judge(new FailingJudge($limit))($this->judgingOf($round));
            self::fail('The limited call was not reported.');
        } catch (JudgeLimited) {
            self::assertSame([], $this->judgeDonePayloads());
            self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
        }
    }

    public function testARateLimitedJudgeCallIsRetriedAfterTheProvidersReset(): void
    {
        $round = $this->completedRound();

        try {
            $this->fixture->judge(new FailingJudge(new ProviderRateLimited(30, new RuntimeException('limited'))))($this->judgingOf($round));
            self::fail('The limited call was not reported.');
        } catch (JudgeLimited $limited) {
            self::assertSame(30_000, $limited->getRetryDelay());
        }
    }

    public function testAHeldAppSecretWithATrailingNewlineIsRedactedWhereTheJudgesTextQuotesItWithoutIt(): void
    {
        $previous = $_SERVER['APP_SECRET'] ?? null;
        $_SERVER['APP_SECRET'] = "probe-app-secret-value\n";

        try {
            $redacted = self::getContainer()->get(Redactor::class)->redact('the judge saw probe-app-secret-value in the diff');
        } finally {
            if (null === $previous) {
                unset($_SERVER['APP_SECRET']);
            } else {
                $_SERVER['APP_SECRET'] = $previous;
            }
        }

        self::assertSame('the judge saw [redacted] in the diff', $redacted);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    protected function tearDown(): void
    {
        $this->checkout?->remove();
        parent::tearDown();
    }

    private function completedRound(): ReviewRound
    {
        $round = $this->fixture->start(2);
        $findings = [$this->finding('A', Severity::P1), $this->finding('B', Severity::P2), $this->finding('C', Severity::P3)];
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]);

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $this->fixture->round($round->id()->toRfc4122());
    }

    // A checkout holding the file the round's diff changes, because the nearest lines are read from the checked-out file.
    private function checkOutThingHolding(string $content): void
    {
        $this->checkout = new TemporaryRepository();
        $this->checkout->commitFile('src/Thing.php', $content);
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes([]), $this->checkout->path);
    }

    // At one place, so a finding that is dropped would otherwise be merged with the other.
    private function quoting(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, 'src/Thing.php', 1, null, $text, FixableBy::Builder, false, null, null);
    }

    private function atLine(string $id, Severity $severity, int $line): Finding
    {
        return new Finding($id, $severity, 'src/Thing.php', $line, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function twoSeparateEntries(int $lineOfB): array
    {
        return [
            ['id' => 'F1', 'severity' => 'P2', 'text' => 'Text of A.', 'merged' => [], 'file' => 'src/Thing.php', 'line' => 3, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null],
            ['id' => 'F2', 'severity' => 'P1', 'text' => 'Text of B.', 'merged' => [], 'file' => 'src/Thing.php', 'line' => $lineOfB, 'judge_note' => null, 'gate_gap' => false, 'title' => null, 'kind' => null],
        ];
    }

    private function judgingOf(ReviewRound $round): JudgeRound
    {
        return new JudgeRound($round->id()->toRfc4122());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function judgeCalls(): array
    {
        return array_map(static fn (array $payload): array => array_diff_key($payload, ['findings' => true, 'dropped' => true, 'same_cause' => true, 'votes' => true, 'p1_keys' => true, 'fallback' => true]), $this->judgeDonePayloads());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function judgeDonePayloads(): array
    {
        $events = array_filter(iterator_to_array($this->fixture->ledger()->readInOrder()), static fn (RecordedEvent $event): bool => LedgerEventKind::JudgeDone === $event->event->kind);

        return array_values(array_map(static function (RecordedEvent $event): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->event->payload, true, 8, JSON_THROW_ON_ERROR);

            return $payload;
        }, $events));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function notesOf(array $payload): array
    {
        $findings = $payload['findings'];
        self::assertIsArray($findings);

        return array_column($findings, 'judge_note', 'id');
    }

    /**
     * @return list<array{string, string}>
     */
    private function askedAbout(ReviewRound $round): array
    {
        $judge = new ScriptedJudgement(new Judgement([]));
        $this->fixture->judge($judge)($this->judgingOf($round));
        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);

        return $this->idsAndTexts($asked);
    }

    /**
     * @return list<array{string, string}>
     */
    private function idsAndTexts(JudgeRequest $request): array
    {
        return array_map(static fn (Finding $finding): array => [$finding->id, $finding->text], $request->findings);
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    /**
     * @param list<Finding> $findings
     */
    private function roundReporting(array $findings): ReviewRound
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]));

        return $this->fixture->round($round->id()->toRfc4122());
    }
}
