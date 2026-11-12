<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Fiber;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\ToolCallCount;
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

// Through the real MercureLiveUpdates over a MockHub, because what callers see is the update that reaches the hub.
#[CoversClass(MercureRoundUpdates::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(SessionSummary::class)]
#[UsesClass(ToolCallCount::class)]
#[UsesClass(Cost::class)]
final class MercureRoundUpdatesTest extends TestCase
{
    private const string ROUND = '01a19a97-48cf-70f1-bdae-965425786c46';

    private const string REVIEWER = '01a19a97-48cf-70f1-bdae-965425786c47';

    /**
     * @var list<Update>
     */
    private array $published = [];

    private BufferingLogger $log;

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
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

        self::assertStringContainsString('Judged.', $this->published[0]->getData());
    }

    public function testAReviewerUpdateBringsThePlaceOfAReviewerThePageDoesNotShowYet(): void
    {
        $this->updates()->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');

        self::assertStringContainsString(sprintf('targets="#round-%s-live:not(:has(#reviewer-%s-text))"', self::ROUND, self::REVIEWER), $this->published[0]->getData());
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

        new MercureRoundUpdates($this->live(), $twig, self::createStub(SessionSummaries::class), new LockFactory(new InMemoryStore()), $this->log)->toolCalled(Uuid::fromString(self::ROUND), self::REVIEWER, 'read_file');

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

        self::assertStringContainsString(sprintf('target="%s-state"><template><td id="%s-state">stopped</td>', $row, $row), $data);
        self::assertStringContainsString(sprintf('target="%s-stop"><template><td id="%s-stop"></td>', $row, $row), $data);
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
        self::assertStringContainsString('<dt>read_file</dt><dd>3</dd>', $this->published[0]->getData());
        self::assertStringContainsString('0.5000 USD', $this->published[0]->getData());
    }

    public function testAJudgedRoundPublishesItsSummaryBeforeItsStateSoTheJudgedStateIsTheLastUpdate(): void
    {
        $summary = new SessionSummary([], Cost::none(), [], [], [], [], false);

        $this->updates(null, $this->summaries($summary))->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(2, $this->published);
        self::assertSame('replace round-'.self::ROUND.'-summary-tools', $this->actionAndTargetOf($this->streamsOf($this->published[0])[0]));
        self::assertSame('replace round-'.self::ROUND.'-state', $this->actionAndTargetOf($this->published[1]));
        self::assertStringContainsString('Judged.', $this->published[1]->getData());
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
        self::assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $this->published[0]->getData());
        self::assertStringNotContainsString('<b>', $this->published[0]->getData());
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
        self::assertStringContainsString('Judged.', array_last($this->published)?->getData() ?? '');
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
        self::assertStringContainsString('Judged.', array_last($this->published)?->getData() ?? '');
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
        self::assertStringContainsString('Judged.', $this->published[0]->getData());
        self::assertSame(
            [['warning', 'A live session summary was not published.', ['round' => self::ROUND, 'exception' => $failure]]],
            $this->log->cleanLogs(),
        );
    }

    public function testAJudgedStateGoesOutWhenTheSummaryLockCannotBeTaken(): void
    {
        $store = self::createStub(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new RuntimeException('the lock store is gone'));

        $this->updates(null, $this->summaries(new SessionSummary([], Cost::none(), [], [], [], [], true)), $store)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(2, 2, true));

        self::assertCount(1, $this->published);
        self::assertStringContainsString('Judged.', $this->published[0]->getData());
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
        self::assertStringContainsString('Judged.', array_last($this->published)?->getData() ?? '');
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
        return new MercureRoundUpdates(
            $this->live($publisher),
            new Environment(new FilesystemLoader(__DIR__.'/../../../../templates')),
            $summaries ?? self::createStub(SessionSummaries::class),
            new LockFactory($locks ?? new InMemoryStore()),
            $this->log,
        );
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
