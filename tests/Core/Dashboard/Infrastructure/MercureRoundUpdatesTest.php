<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Fiber;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Infrastructure\ComponentOptions;
use Intakt\Core\Dashboard\Infrastructure\FindingSpans;
use Intakt\Core\Dashboard\Infrastructure\GlossaryExplanations;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\ReadFinding;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\ToolCallCount;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Core\Dashboard\UseCase\ReviewBoundLedger;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

// Through the real MercureLiveUpdates over a MockHub, because what callers see is the update that reaches the hub.
#[CoversClass(MercureRoundUpdates::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(FinishedState::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(SessionSummary::class)]
#[UsesClass(ToolCallCount::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ComponentOptions::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReadFinding::class)]
#[UsesClass(ReadAnswer::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
final class MercureRoundUpdatesTest extends TestCase
{
    private const string ROUND = '01a19a97-48cf-70f1-bdae-965425786c46';

    private const string REVIEWER = '01a19a97-48cf-70f1-bdae-965425786c47';

    /**
     * @var list<Update>
     */
    private array $published = [];

    private BufferingLogger $log;

    private Ledger $ledger;

    private RoundCosts $costs;

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
        $this->ledger = new DashboardLedger();
        $this->costs = self::createStub(RoundCosts::class);
        $this->costs->method('costOf')->willReturn(Cost::none());
    }

    public function testAJudgedStateAsksTheCostOfTheRoundsOfItsOwnReviewOnly(): void
    {
        $other = '01a19a97-48cf-70f1-bdae-965425786c48';
        $earlier = '01a19a97-48cf-70f1-bdae-965425786c49';
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/other', $other, 1);
        $ledger->judgeRound($other);
        $ledger->startRoundOf('feat/thing', $earlier, 1);
        $ledger->judgeRound($earlier);
        $ledger->startRoundOf('feat/thing', self::ROUND, 2);
        $ledger->judgeRound(self::ROUND);
        $this->ledger = $ledger;
        $asked = [];
        $costs = $this->createMock(RoundCosts::class);
        $costs->expects($this->atLeastOnce())->method('costOf')->willReturnCallback(static function (string $round) use (&$asked): Cost {
            $asked[$round] = true;

            return Cost::none();
        });
        $this->costs = $costs;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertEqualsCanonicalizing([$earlier, self::ROUND], array_map(strval(...), array_keys($asked)));
    }

    public function testAJudgedStateIsReadFromItsOwnReviewWithoutReadingTheWholeLedger(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/other', '01a19a97-48cf-70f1-bdae-965425786c48', 1);
        $ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $ledger->useTokens(self::ROUND, 1000, 200);
        $ledger->judgeFindings(self::ROUND, []);
        $this->ledger = new ReviewBoundLedger($ledger);

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        $judged = array_last($this->published);
        self::assertNotNull($judged);
        self::assertStringContainsString('<span id="review-summary-tokens">1,200 tokens', $judged->getData());
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testUpdatesOfARoundReachTheHubInTheOrderTheyHappened(): void
    {
        $updates = $this->updates();

        $updates->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');
        $updates->textStreamed(Uuid::fromString(self::ROUND), self::REVIEWER, 'Looking at the cart.');
        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame(
            ['append reviewer-'.self::REVIEWER.'-activity', 'append reviewer-'.self::REVIEWER.'-text', 'replace round-'.self::ROUND.'-state'],
            array_map($this->actionAndTargetOf(...), $this->published),
        );
    }

    public function testEveryUpdateOfARoundIsOnTheRoundsTopic(): void
    {
        $updates = $this->updates();

        $updates->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');
        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame([['review/rounds/'.self::ROUND], ['review/rounds/'.self::ROUND]], array_map(static fn (Update $update): array => $update->getTopics(), $this->published));
    }

    public function testAnUpdateIsPrivateBecauseItCarriesModelOutputAboutPrivateCode(): void
    {
        $this->updates()->textStreamed(Uuid::fromString(self::ROUND), self::REVIEWER, 'A line of code.');

        self::assertTrue($this->published[0]->isPrivate());
    }

    public function testStreamedModelTextIsEscapedInTheTurboStream(): void
    {
        $this->updates()->textStreamed(Uuid::fromString(self::ROUND), self::REVIEWER, '</template><script>alert(1)</script>');

        self::assertStringContainsString('&lt;/template&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $this->published[0]->getData());
        self::assertStringNotContainsString('<script>', $this->published[0]->getData());
    }

    public function testAToolNameIsEscapedInTheTurboStream(): void
    {
        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, '<b>x</b>');

        self::assertStringContainsString('<li>&lt;b&gt;x&lt;/b&gt;</li>', $this->published[0]->getData());
    }

    public function testARoundInProgressShowsHowManyReviewersFinished(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertStringContainsString('1 of 2 reviewers finished.', $this->published[0]->getData());
    }

    public function testAJudgedRoundShowsItIsJudged(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertStringContainsString('Checked.', $this->published[0]->getData());
    }

    public function testAJudgedRoundShowsItsRoundHeadAndNowBoxAsJudged(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertStringContainsString('target="round-'.self::ROUND.'-head-state"', $this->published[0]->getData());
        self::assertStringContainsString('<span id="round-'.self::ROUND.'-now-phase"><span class="term term-ask" tabindex="0" data-term="checked"', $this->published[0]->getData());
    }

    public function testARoundInProgressLeavesItsRoundHeadAndNowPhaseAlone(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertStringNotContainsString('head-state', $this->published[0]->getData());
        self::assertStringNotContainsString('now-phase', $this->published[0]->getData());
    }

    public function testAReviewerUpdateBringsThePlaceOfAReviewerThePageDoesNotShowYet(): void
    {
        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');

        self::assertStringContainsString(sprintf('targets="#round-%s-live:not(:has(#reviewer-%s-text))"', self::ROUND, self::REVIEWER), $this->published[0]->getData());
    }

    public function testAReviewerAskedAgainReplacesItsLiveTextWithOneLineForThatTryUnlessThePageHoldsItAlready(): void
    {
        $this->updates()->reviewerAskedAgain(Uuid::fromString(self::ROUND), self::REVIEWER, 'try-2');

        $data = $this->published[0]->getData();
        self::assertStringContainsString(sprintf('<turbo-stream action="update" targets="#reviewer-%1$s-text:not(:has(#reviewer-%1$s-try-try-2))">', self::REVIEWER), $data);
        self::assertStringContainsString(sprintf('<p class="asked-again" id="reviewer-%s-try-try-2">First try gave no usable answer, so the reviewer was asked again.</p>', self::REVIEWER), $data);
        self::assertStringContainsString(sprintf('targets="#round-%s-live:not(:has(#reviewer-%s-text))"', self::ROUND, self::REVIEWER), $data);
    }

    public function testAReadAnswerPublishesItsVerdictAndEachFindingsSeverityFileLineAndTitleAndNoJson(): void
    {
        $this->updates()->answerRead(Uuid::fromString(self::ROUND), self::REVIEWER, $this->readAnswer('Discount boundary regression'));

        $data = $this->published[0]->getData();
        self::assertSame(['review/rounds/'.self::ROUND], $this->published[0]->getTopics());
        self::assertStringContainsString('Verdict: changes', $data);
        self::assertStringContainsString('P1 src/Cart.php:12 Discount boundary regression', $data);
        self::assertStringContainsString(sprintf('target="reviewer-%s-text"', self::REVIEWER), $data);
        self::assertStringNotContainsString('{"', $data);
        self::assertStringNotContainsString('"findings"', $data);
    }

    public function testAnUnreadableAnswerPublishesOnlyTheCouldNotBeReadMarker(): void
    {
        $this->updates()->answerRead(Uuid::fromString(self::ROUND), self::REVIEWER, ReadAnswer::unreadable());

        self::assertCount(1, $this->published);
        self::assertStringContainsString('The answer could not be read.', $this->published[0]->getData());
        self::assertStringNotContainsString('Verdict:', $this->published[0]->getData());
    }

    public function testAFindingTitleIsEscapedInTheTurboStream(): void
    {
        $this->updates()->answerRead(Uuid::fromString(self::ROUND), self::REVIEWER, $this->readAnswer('</template><script>x</script>'));

        self::assertStringContainsString('&lt;/template&gt;&lt;script&gt;x&lt;/script&gt;', $this->published[0]->getData());
        self::assertStringNotContainsString('<script>', $this->published[0]->getData());
    }

    private function readAnswer(string $title): ReadAnswer
    {
        return ReadAnswer::of(new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'src/Cart.php', 12, null, $title, FixableBy::Builder, false, null, null)], [], []));
    }

    public function testAHubThatRefusesTheUpdateDoesNotFailTheCallerAndIsLoggedOnceByTheLiveUpdates(): void
    {
        $refusal = new RuntimeException('the hub refused the update');
        $updates = $this->updates(static fn (Update $update): string => throw $refusal);

        $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertSame(
            [['warning', 'A live dashboard update was not published.', ['topic' => 'review/rounds/'.self::ROUND, 'exception' => $refusal]]],
            $this->log->cleanLogs(),
        );
    }

    public function testATemplateThatFailsToRenderIsLoggedWithItsRoundAndStreamAndPublishesNothing(): void
    {
        $failure = new LoaderError('Template "dashboard/stream/tool_called.stream.html.twig" is not defined.');
        $twig = self::createStub(Environment::class);
        $twig->method('render')->willThrowException($failure);

        new MercureRoundUpdates($this->live(), $twig, self::createStub(SessionSummaries::class), $this->pages(), new LockFactory(new InMemoryStore()), $this->log)->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');

        self::assertSame(
            [['warning', 'A live round update was not rendered.', ['round' => self::ROUND, 'stream' => 'tool_called', 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
        self::assertSame([], $this->published);
    }

    public function testAStoppedReviewerReplacesItsStateAndEmptiesItsStopCell(): void
    {
        $this->updates()->reviewerStopped(Uuid::fromString(self::ROUND), self::REVIEWER);

        $data = $this->published[0]->getData();
        $row = 'round-'.self::ROUND.'-reviewer-'.self::REVIEWER;

        self::assertStringContainsString(sprintf('target="%s-state"><template><td id="%s-state"><span class="muted">stopped</span></td>', $row, $row), $data);
        self::assertStringContainsString(sprintf('targets="#%s-stop"><template><td id="%s-stop"></td>', $row, $row), $data);
    }

    public function testAHubThatRefusesAStoppedReviewerIsLoggedAndDoesNotFailTheCaller(): void
    {
        $refusal = new RuntimeException('the hub refused the update');

        $this->updates(static fn (Update $update): string => throw $refusal)->reviewerStopped(Uuid::fromString(self::ROUND), self::REVIEWER);

        self::assertSame(
            [['warning', 'A live dashboard update was not published.', ['topic' => 'review/rounds/'.self::ROUND, 'exception' => $refusal]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAChangedSummaryReplacesEachLivePartOfTheSummaryWithTheRecomputedCounts(): void
    {
        $summary = new SessionSummary([new ToolCallCount('read_file', 3)], Cost::inUsd(0.5), ['src/A.php'], [], [], [], false);

        $this->updates(null, $this->summaries($summary))->summaryChanged(Uuid::fromString(self::ROUND));

        $base = 'round-'.self::ROUND.'-summary';
        self::assertSame(
            ['replace '.$base.'-tools', 'replace '.$base.'-cost', 'replace '.$base.'-files', 'replace '.$base.'-findings'],
            array_map($this->actionAndTargetOf(...), $this->streamsOf($this->published[0])),
        );
        self::assertStringContainsString('<dt>File reads</dt><dd>3</dd>', $this->published[0]->getData());
        self::assertStringContainsString('0.5000 USD', $this->published[0]->getData());
    }

    public function testAJudgedRoundPublishesItsSummaryBeforeItsStateSoTheJudgedStateIsTheLastUpdate(): void
    {
        $summary = new SessionSummary([], Cost::none(), [], [], [], [], false);

        $this->updates(null, $this->summaries($summary))->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(2, $this->published);
        self::assertSame('replace round-'.self::ROUND.'-summary-tools', $this->actionAndTargetOf($this->streamsOf($this->published[0])[0]));
        self::assertSame('replace round-'.self::ROUND.'-state', $this->actionAndTargetOf($this->published[1]));
        self::assertStringContainsString('Checked.', $this->published[1]->getData());
    }

    public function testARoundWithoutARecordedSummaryPublishesNoSummary(): void
    {
        $this->updates(null, $this->summaries(null))->summaryChanged(Uuid::fromString(self::ROUND));

        self::assertSame([], $this->published);
    }

    public function testAFileNameInTheSummaryIsEscapedInTheTurboStream(): void
    {
        $summary = new SessionSummary([new ToolCallCount('<i>x</i>', 1)], Cost::none(), ['<b>x</b>.php'], [], [], [], false);

        $this->updates(null, $this->summaries($summary))->summaryChanged(Uuid::fromString(self::ROUND));

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;.php', $this->published[0]->getData());
        self::assertStringNotContainsString('<b>', $this->published[0]->getData());
        self::assertStringNotContainsString('<i>', $this->published[0]->getData());
    }

    public function testASummaryThatCannotBeComputedIsLoggedAndPublishesNothingButTheRoundChangeStillGoesOut(): void
    {
        $failure = new RuntimeException('the store is gone');
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willThrowException($failure);

        $this->updates(null, $summaries)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false));

        self::assertCount(1, $this->published);
        self::assertSame(
            [['warning', 'A live session summary was not published.', ['round' => self::ROUND, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testTheReReadAfterTheLockIsReleasedAddsNoSecondPublish(): void
    {
        $updates = $this->updates(null, $this->summaries(new SessionSummary([new ToolCallCount('read_file', 1)], Cost::none(), [], [], [], [], false)));

        $updates->summaryChanged(Uuid::fromString(self::ROUND));

        self::assertCount(1, $this->published);
    }

    // The older worker is paused inside its read, holding the lock, so the newer worker runs between its read and its publish: the order that showed the older count last.
    public function testASummaryReadBeforeANewerOneIsNeverTheLastPublished(): void
    {
        $count = 1;
        $paused = false;
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturnCallback(static function () use (&$count, &$paused): SessionSummary {
            $summary = new SessionSummary([new ToolCallCount('read_file', $count)], Cost::none(), [], [], [], [], false);
            if (!$paused) {
                $paused = true;
                Fiber::suspend();
            }

            return $summary;
        });
        $updates = $this->updates(null, $summaries);
        $older = new Fiber(static fn () => $updates->summaryChanged(Uuid::fromString(self::ROUND)));

        $older->start();
        $count = 2;
        $updates->summaryChanged(Uuid::fromString(self::ROUND));
        $older->resume();

        self::assertTrue($older->isTerminated());
        self::assertStringContainsString('<dt>Total</dt><dd>2</dd>', array_last($this->published)?->getData() ?? '');
    }

    public function testTheSummaryOfAnotherRoundIsPublishedWhileOneRoundHoldsItsLock(): void
    {
        $paused = false;
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturnCallback(static function () use (&$paused): SessionSummary {
            if (!$paused) {
                $paused = true;
                Fiber::suspend();
            }

            return new SessionSummary([], Cost::none(), [], [], [], [], false);
        });
        $updates = $this->updates(null, $summaries);
        $held = new Fiber(static fn () => $updates->summaryChanged(Uuid::fromString(self::ROUND)));

        $held->start();
        $updates->summaryChanged(Uuid::fromString(self::REVIEWER));

        self::assertSame([['review/rounds/'.self::REVIEWER]], array_map(static fn (Update $update): array => $update->getTopics(), $this->published));
        $held->resume();
    }

    // The tool call's publisher is paused inside its read, holding the lock, so the judge arrives while it is mid-publish: the order that published its summary after the judged state.
    public function testAJudgedStateWaitsForTheToolCallPublishingItsSummaryAndStaysTheLastUpdate(): void
    {
        $updates = $this->updates(null, $this->summariesJudgedAfterTheFirstRead(), new FiberYieldingLockStore());
        $toolCall = new Fiber(static fn () => $updates->summaryChanged(Uuid::fromString(self::ROUND)));
        $judge = new Fiber(static fn () => $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true)));

        $toolCall->start();
        $judge->start();
        self::assertTrue($judge->isSuspended(), 'the judge waits for the tool call to finish');
        $toolCall->resume();
        $judge->resume();

        self::assertTrue($toolCall->isTerminated());
        self::assertTrue($judge->isTerminated());
        $summary = 'replace round-'.self::ROUND.'-summary-tools';
        self::assertSame([$summary, $summary, 'replace round-'.self::ROUND.'-state'], array_map($this->actionAndTargetOf(...), $this->published));
        self::assertStringContainsString('Checked.', array_last($this->published)?->getData() ?? '');
    }

    // The judge is paused inside its read, holding the lock, so the tool call's publisher finds it busy and leaves the summary to the judge, because nothing may follow the judged state.
    public function testAToolCallThatFindsTheJudgePublishingLeavesItsSummaryToTheJudge(): void
    {
        $updates = $this->updates(null, $this->summariesJudgedAfterTheFirstRead());
        $judge = new Fiber(static fn () => $updates->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true)));

        $judge->start();
        $updates->summaryChanged(Uuid::fromString(self::ROUND));
        $judge->resume();

        self::assertTrue($judge->isTerminated());
        self::assertSame(['replace round-'.self::ROUND.'-summary-tools', 'replace round-'.self::ROUND.'-state'], array_map($this->actionAndTargetOf(...), $this->published));
        self::assertStringContainsString('Checked.', array_last($this->published)?->getData() ?? '');
    }

    public function testAToolCallRecordedAfterTheRoundWasJudgedPublishesNoSummary(): void
    {
        $judged = new SessionSummary([new ToolCallCount('read_file', 2)], Cost::none(), [], [], [], [], true);

        $this->updates(null, $this->summaries($judged))->summaryChanged(Uuid::fromString(self::ROUND));

        self::assertSame([], $this->published);
    }

    public function testAJudgedRoundWhoseSummaryCannotBeComputedStillPublishesItsJudgedState(): void
    {
        $failure = new RuntimeException('the store is gone');
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willThrowException($failure);

        $this->updates(null, $summaries)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('Checked.', $this->published[0]->getData());
        self::assertSame(
            [['warning', 'A live session summary was not published.', ['round' => self::ROUND, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAJudgedRoundCarriesTheJudgesCallAndTheFindingsInItsJudgedState(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $ledger->judgeFindings(self::ROUND, ['F1' => 'act']);
        $this->ledger = $ledger;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        $judged = array_last($this->published);
        self::assertNotNull($judged);
        self::assertContains('replace round-'.self::ROUND.'-judged', array_map($this->actionAndTargetOf(...), $this->streamsOf($judged)));
        self::assertStringContainsString('<span class="fc-text">Text of F1.</span>', $judged->getData());
        self::assertStringContainsString('<p class="fc-call">Fix now</p>', $judged->getData());
    }

    public function testAJudgedRoundCarriesItsTokensTableAndTheReviewsTotalsInItsJudgedState(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $ledger->useTokens(self::ROUND, 1000, 200);
        $ledger->judgeFindings(self::ROUND, []);
        $this->ledger = $ledger;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        $judged = array_last($this->published);
        self::assertNotNull($judged);
        $targets = array_map($this->actionAndTargetOf(...), $this->streamsOf($judged));
        foreach (['round-'.self::ROUND.'-tokens', 'review-summary-tokens', 'review-summary-cost', 'round-'.self::ROUND.'-review-cost'] as $target) {
            self::assertContains('replace '.$target, $targets);
        }
        self::assertStringContainsString('<span id="review-summary-tokens">1,200 tokens', $judged->getData());
    }

    public function testAJudgedRoundWhoseLedgerCannotBeReadGoesOutWithoutItsTokensAndTheReviewsTotals(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willThrowException(new RuntimeException('the ledger is gone'));
        $this->ledger = $ledger;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(1, $this->published);
        foreach (['round-'.self::ROUND.'-tokens', 'review-summary-tokens', 'review-summary-cost', 'round-'.self::ROUND.'-review-cost'] as $target) {
            self::assertStringNotContainsString('target="'.$target.'"', $this->published[0]->getData());
        }
        self::assertCount(1, $this->log->cleanLogs());
    }

    public function testARoundChangeWhoseRoundCannotBeReadStillPublishesTheReviewerStatesAsProcessTextAndLogsWhy(): void
    {
        $failure = new RuntimeException('the ledger is gone');
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willThrowException($failure);
        $this->ledger = $ledger;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false, [self::REVIEWER => FinishedState::Verdict]));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('<span class="muted">answered</span>', $this->published[0]->getData());
        self::assertStringNotContainsString('verdict', $this->published[0]->getData());
        self::assertSame(
            [['warning', 'The round was not read for its live reviewer rows.', ['round' => self::ROUND, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAJudgedRoundWhoseFindingsCannotBeReadStillPublishesItsJudgedStateAndLogsWhy(): void
    {
        $failure = new RuntimeException('the ledger is gone');
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willThrowException($failure);
        $this->ledger = $ledger;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('Checked.', $this->published[0]->getData());
        self::assertStringNotContainsString('target="round-'.self::ROUND.'-judged"', $this->published[0]->getData());
        self::assertSame(
            [['warning', 'The judged round was not read for its live update.', ['round' => self::ROUND, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAToolCallWhoseReviewerCannotBeReadStillGoesOutUnnamedAndLogsWhy(): void
    {
        $failure = new RuntimeException('the ledger is gone');
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKindAbout')->willThrowException($failure);
        $this->ledger = $ledger;

        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');

        self::assertCount(1, $this->published);
        self::assertStringContainsString('A reviewer (role not read)', $this->published[0]->getData());
        self::assertSame(
            [['warning', 'The reviewer was not read for its live update.', ['round' => self::ROUND, 'reviewer' => self::REVIEWER, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAProgressWhoseRoundCannotBeReadStillGoesOutWithTheFinishedReviewerUnnamedAndLogsWhy(): void
    {
        $failure = new RuntimeException('the ledger is gone');
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willThrowException($failure);
        $this->ledger = $ledger;

        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(1, 2, false, [self::REVIEWER => FinishedState::Failed]));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('<td>role not read</td>', $this->published[0]->getData());
        self::assertSame(
            [['warning', 'The round was not read for its live reviewer rows.', ['round' => self::ROUND, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testARoundUpdateNamesAReviewerOfAnUnknownModelByItsIdAlone(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $ledger->startReviewerOn(self::ROUND, 'security', 'acme/robot-7');
        $this->ledger = $ledger;

        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::ROUND.'-security', 'read_file');

        self::assertStringContainsString('<h3 title="acme/robot-7">security (acme/robot-7) live</h3>', $this->published[0]->getData());
    }

    public function testAToolCallNamesItsReviewerByTheRoleAndModelOfItsLatestStart(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $ledger->startReviewerOn(self::ROUND, 'security', 'GLM 5');
        $ledger->startReviewerOn(self::ROUND, 'security', 'Kimi K3');
        $this->ledger = $ledger;

        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::ROUND.'-security', 'read_file');

        self::assertStringContainsString('<h3 title="Kimi K3">security (Kimi K3 (Moonshot AI)) live</h3>', $this->published[0]->getData());
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testAReadAnswerAndARetryNameTheirReviewerByTheRoleAndModelOfItsLatestStart(): void
    {
        $ledger = new DashboardLedger();
        $ledger->startRoundOf('feat/thing', self::ROUND, 1);
        $ledger->startReviewerOn(self::ROUND, 'security', 'Kimi K3');
        $this->ledger = $ledger;
        $updates = $this->updates();

        $updates->answerRead(Uuid::fromString(self::ROUND), self::ROUND.'-security', $this->readAnswer('Discount boundary regression'));
        $updates->reviewerAskedAgain(Uuid::fromString(self::ROUND), self::ROUND.'-security', 'try-2');

        self::assertCount(2, $this->published);
        foreach ($this->published as $update) {
            self::assertStringContainsString('<h3 title="Kimi K3">security (Kimi K3 (Moonshot AI)) live</h3>', $update->getData());
        }
    }

    public function testAJudgedRoundTheLedgerDoesNotHoldPublishesItsJudgedStateWithoutFindings(): void
    {
        $this->updates()->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(1, $this->published);
        self::assertStringNotContainsString('target="round-'.self::ROUND.'-judged"', $this->published[0]->getData());
        self::assertStringContainsString('Reload the page', $this->published[0]->getData());
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testAJudgedStateGoesOutWhenTheSummaryLockCannotBeTaken(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new RuntimeException('the lock store is gone'));

        $this->updates(null, $this->summaries(new SessionSummary([], Cost::none(), [], [], [], [], true)), $store)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('Checked.', $this->published[0]->getData());
        $logs = $this->log->cleanLogs();
        self::assertCount(1, $logs);
        $log = $logs[0];
        self::assertIsArray($log);
        self::assertSame(['warning', 'A live session summary was not published.'], [$log[0], $log[1]]);
        $context = $log[2];
        self::assertIsArray($context);
        self::assertInstanceOf(LockAcquiringException::class, $context['exception']);
    }

    public function testAJudgedStateGoesOutAndNothingIsThrownWhenTheSummaryLockCannotBeReleased(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new RuntimeException('the lock store is gone'));

        $this->updates(null, $this->summaries(new SessionSummary([], Cost::none(), [], [], [], [], true)), $store)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertSame(['replace round-'.self::ROUND.'-summary-tools', 'replace round-'.self::ROUND.'-state'], array_map($this->actionAndTargetOf(...), $this->published));
        self::assertStringContainsString('Checked.', array_last($this->published)?->getData() ?? '');
        self::assertSame([LockReleasingException::class], $this->exceptionsLogged());
    }

    public function testASummaryLockThatCannotBeReleasedAfterAToolCallIsLoggedAndNotThrown(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('delete')->willThrowException(new RuntimeException('the lock store is gone'));

        $this->updates(null, $this->summaries(new SessionSummary([new ToolCallCount('read_file', 1)], Cost::none(), [], [], [], [], false)), $store)->summaryChanged(Uuid::fromString(self::ROUND));

        self::assertSame(['replace round-'.self::ROUND.'-summary-tools'], array_map($this->actionAndTargetOf(...), $this->published));
        self::assertSame([LockReleasingException::class], $this->exceptionsLogged());
    }

    /**
     * @return list<class-string>
     */
    private function exceptionsLogged(): array
    {
        $classes = [];

        foreach ($this->log->cleanLogs() as $log) {
            self::assertIsArray($log);
            $context = $log[2];
            self::assertIsArray($context);
            $exception = $context['exception'];
            self::assertIsObject($exception);
            $classes[] = $exception::class;
        }

        return $classes;
    }

    /**
     * @param (callable(Update): string)|null $publisher
     */
    private function updates(?callable $publisher = null, ?SessionSummaries $summaries = null, ?PersistingStoreInterface $locks = null): MercureRoundUpdates
    {
        // Registered because the judged stream renders a component, which asks for its options through this function.
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../../../templates'));
        $twig->addFunction(new TwigFunction('component_option', new ComponentOptions()->of(...)));
        // Registered because a placed reviewer row shows a character, whose images the asset mapper names in the app.
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
        // Registered because the judged stream links the Now box's findings to the Full record by its route.
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => $route.'?'.http_build_query($parameters)));
        // Registered because a judged finding's text shows its backtick spans as code.
        $twig->addFunction(new TwigFunction('finding_spans', new FindingSpans()->of(...)));
        $twig->addFunction(new TwigFunction('finding_title_spans', new FindingSpans()->ofATitle(...)));
        // Registered because the judged stream explains "checked" with its glossary term.
        $twig->addFunction(new TwigFunction('glossary_explanation', new GlossaryExplanations()->of(...)));

        return new MercureRoundUpdates(
            $this->live($publisher),
            $twig,
            $summaries ?? self::createStub(SessionSummaries::class),
            $this->pages(),
            new LockFactory($locks ?? new InMemoryStore()),
            $this->log,
        );
    }

    private function pages(): LedgerReviewPages
    {
        return new LedgerReviewPages($this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $this->costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));
    }

    // The first read pauses its fiber and answers a running round, because it stands for a read taken before the judge committed; every later one finds the round judged, since the judge commits before it publishes.
    private function summariesJudgedAfterTheFirstRead(): SessionSummaries
    {
        $reads = 0;
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturnCallback(static function () use (&$reads): SessionSummary {
            if (0 !== $reads++) {
                return new SessionSummary([new ToolCallCount('read_file', 2)], Cost::none(), [], [], [], [], true);
            }

            Fiber::suspend();

            return new SessionSummary([new ToolCallCount('read_file', 1)], Cost::none(), [], [], [], [], false);
        });

        return $summaries;
    }

    private function summaries(?SessionSummary $summary): SessionSummaries
    {
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn($summary);

        return $summaries;
    }

    /**
     * @param (callable(Update): string)|null $publisher
     */
    private function live(?callable $publisher = null): MercureLiveUpdates
    {
        $publisher ??= function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        };

        return new MercureLiveUpdates(new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), $publisher), $this->log);
    }

    /**
     * @return list<string>
     */
    private function streamsOf(Update $update): array
    {
        return array_values(array_filter(explode('</turbo-stream>', $update->getData()), static fn (string $stream): bool => str_contains($stream, '<turbo-stream')));
    }

    private function actionAndTargetOf(Update|string $update): string
    {
        preg_match('/action="(\w+)" target="([^"]+)"/', $update instanceof Update ? $update->getData() : $update, $found);

        return ($found[1] ?? '').' '.($found[2] ?? '');
    }
}
