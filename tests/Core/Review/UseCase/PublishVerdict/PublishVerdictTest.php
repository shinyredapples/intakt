<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\PublishVerdict;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\ProtectedHeads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\MarkVerdictsStale;
use Intakt\Core\Review\Contract\PublishHandedOverChange;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\CountedVote;
use Intakt\Core\Review\Domain\ReplayProject;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\MarkReplay\FlagProjectAsReplayHandler;
use Intakt\Core\Review\UseCase\MarkVerdictsStale\MarkVerdictsStaleHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\ChangePublications;
use Intakt\Core\Review\UseCase\PublishVerdict\LoopReviewComment;
use Intakt\Core\Review\UseCase\PublishVerdict\OwnerAsk;
use Intakt\Core\Review\UseCase\PublishVerdict\OwnerAskNotRecorded;
use Intakt\Core\Review\UseCase\PublishVerdict\OwnerAsks;
use Intakt\Core\Review\UseCase\PublishVerdict\PublicationBusy;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishHandedOverChangeHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdictHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\QueuePublicationOfJudgedRound;
use Intakt\Core\Review\UseCase\PublishVerdict\VerdictForTheLoop;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\ReviewerForLoop\Contract\LoopVerdict;
use Intakt\Core\ReviewerForLoop\Contract\PullRequestComment;
use Intakt\Core\ReviewerForLoop\Domain\LoopReviewReader;
use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\ChangesHandedToIntakt;
use Intakt\Core\Watcher\UseCase\WatchPullRequests\WatcherLedger;
use Intakt\Plugin\Forge\Contract\ChangeAuthorship;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\OpenChange;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use Intakt\Tests\Core\Dashboard\UseCase\AppendRefusingLedger;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\DeferringJudge;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Plugin\GitHubForge\ScriptedGitHub;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

// A real round on SQLite, judged by the application's workflow, published through the GitHub forge over a scripted GitHub, so the test sees the very requests the loop would read.
#[Group('persistence')]
#[CoversClass(PublishVerdictHandler::class)]
#[CoversClass(PublishVerdict::class)]
#[CoversClass(ChangePublications::class)]
#[CoversClass(PublicationBusy::class)]
#[CoversClass(QueuePublicationOfJudgedRound::class)]
#[CoversClass(VerdictForTheLoop::class)]
#[CoversClass(LoopReviewComment::class)]
#[CoversClass(OwnerAsks::class)]
#[CoversClass(OwnerAsk::class)]
#[CoversClass(OwnerAskNotRecorded::class)]
#[CoversClass(PublishHandedOverChangeHandler::class)]
#[UsesClass(PublishHandedOverChange::class)]
#[UsesClass(ChangesHandedToIntakt::class)]
#[UsesClass(WatcherLedger::class)]
#[UsesClass(HandedOverChanges::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(ProtectedChanges::class)]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(ReviewRound::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(PublishedVerdict::class)]
#[UsesClass(HeadStatus::class)]
#[UsesClass(LoopReviewReader::class)]
#[UsesClass(PostedComments::class)]
#[UsesClass(VerdictLocks::class)]
#[UsesClass(ReviewToken::class)]
#[UsesClass(MarkVerdictsStaleHandler::class)]
#[UsesClass(MarkVerdictsStale::class)]
#[UsesClass(FlagProjectAsReplayHandler::class)]
#[UsesClass(FlagProjectAsReplay::class)]
#[UsesClass(ReplayProject::class)]
#[UsesClass(ReplayMark::class)]
#[UsesClass(CountedVote::class)]
#[UsesClass(StoredLatestRoundStatus::class)]
final class PublishVerdictTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string API = 'https://api.github.com/repos/acme/widgets';
    private const string STATUS = 'POST '.self::API.'/statuses/'.SampleIdentity::HEAD;
    private const string COMMENT = 'POST '.self::API.'/issues/7/comments';
    private const string HEADING = '**Review by Intakt** (head aaaaaaaaaaaa)';
    private const array WITHDRAWN = ['state' => 'failure', 'context' => 'intakt/review', 'description' => 'Intakt withdrew its review: this head does not boot.'];

    private RoundFixture $fixture;
    private ScriptedGitHub $github;
    private LockFactory $locks;

    private ProtectedChanges $protectedChanges;

    public function testAPullRequestHandedToIntaktInALiveProjectGetsTheReviewPostedWithTheRoundsToken(): void
    {
        $this->handOver('alpha', self::PULL);

        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame([
            [self::COMMENT, ['body' => self::HEADING."\n\n```json\n".'{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 0."}'."\n```\n\n".$this->marker($roundId)]],
            [self::STATUS, ['state' => 'success', 'context' => 'intakt/review', 'description' => 'Intakt approves this head.']],
        ], $this->github->writes);
        self::assertSame([], $this->ownerAsks());
    }

    public function testAPullRequestHandedToIntaktInAnotherLetterCaseIsPublishedToo(): void
    {
        $this->handOver('alpha', 'https://github.com/Acme/Widgets/pull/7');

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame(['success'], array_column(array_column($this->github->writes, 1), 'state'));
    }

    public function testAPullRequestHandedToIntaktInAnotherProjectPublishesNothingInALiveProject(): void
    {
        $this->handOver('beta', self::PULL);

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame([], $this->github->writes);
    }

    public function testAHandedOverHeadThatTouchesProtectedPathsGetsTheReviewWithTheOwnerAskAndAPendingStatus(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('.github/workflows/ci.yaml', 'bin/check'));

        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        $ask = $this->ask('Protected paths:', '- <code>.github/workflows/ci.yaml</code>', '- <code>bin/check</code>');
        self::assertSame([
            [self::COMMENT, ['body' => $this->approvedComment($roundId)]],
            [self::COMMENT, ['body' => $ask."\n\n".$this->askMarker($roundId, 1, $ask)]],
            [self::STATUS, ['state' => 'pending', 'context' => 'intakt/review', 'description' => 'Intakt approves this head; it touches protected paths and waits for the owner.']],
        ], $this->github->writes);
        self::assertSame([['project' => 'alpha', 'change' => self::PULL, 'head' => SampleIdentity::HEAD, 'round' => $roundId, 'paths' => ['.github/workflows/ci.yaml', 'bin/check'], 'unreadable' => null]], $this->ownerAsks());
    }

    public function testAHandedOverHeadWhoseProtectedPathsCannotBeReadAsksTheOwnerWithoutPostingGitsReason(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::unreadable('/var/lib/intakt/mirrors/alpha.git is no folder');

        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        $ask = $this->ask('Intakt could not read which paths this head changes, so it counts as protected.');
        self::assertSame([$ask."\n\n".$this->askMarker($roundId, 1, $ask)], $this->askBodies());
        self::assertSame(['pending'], array_column(array_column($this->github->writes, 1), 'state'));
        self::assertSame(['/var/lib/intakt/mirrors/alpha.git is no folder'], array_column($this->ownerAsks(), 'unreadable'));
    }

    public function testAProtectedPathThatCouldForgeTheAskIsPostedEscaped(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths("bin/a\n- <code>b</code> `c` <!-- d **e** #12 [f](g)\\h"));

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertStringContainsString("Protected paths:\n- <code>bin/a&#92;n- &#60;code&#62;b&#60;/code&#62; &#96;c&#96; &#60;&#33;-- d &#42;&#42;e&#42;&#42; &#35;12 &#91;f&#93;&#40;g&#41;&#92;&#92;h</code>\n\n<!-- intakt-owner-ask: ", $this->askBodies()[0]);
    }

    public function testAProtectedPathThatIsNotUtf8IsPostedWithItsInvalidBytesReplaced(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths("bin/\xff"));

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertStringContainsString("- <code>bin/?</code>\n", $this->askBodies()[0]);
    }

    public function testAnAskListsEveryProtectedPath(): void
    {
        $this->handOver('alpha', self::PULL);
        $paths = array_map(static fn (int $n): string => sprintf('bin/%02d', $n), range(1, 51));
        $this->protectedChanges = ProtectedChanges::touching(new Paths(...$paths));

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame($paths, $this->listedPaths());
        self::assertCount(1, $this->askBodies());
        self::assertSame([$paths], array_column($this->ownerAsks(), 'paths'));
    }

    public function testAnAskTooLongForOneCommentIsPostedInPartsThatTogetherListEveryProtectedPath(): void
    {
        $this->handOver('alpha', self::PULL);
        $paths = array_map(static fn (int $n): string => sprintf('protected/%s/%04d', str_repeat('x', 60), $n), range(1, 2000));
        $this->protectedChanges = ProtectedChanges::touching(new Paths(...$paths));

        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        $parts = $this->askBodies();
        self::assertCount(4, $parts);
        self::assertSame($paths, $this->listedPaths());
        self::assertSame([], array_filter($parts, static fn (string $part): bool => mb_strlen($part) > 65536));
        self::assertStringStartsWith('**Waiting for the owner** (head '.SampleIdentity::HEAD."), continued\n\n- <code>", $parts[3]);
        self::assertStringEndsWith($this->askMarker($roundId, 4, ...array_map(static fn (string $part): string => substr($part, 0, (int) strrpos($part, "\n\n")), $parts)), $parts[3]);
    }

    public function testAProtectedFileNameThatHoldsASecretPublishesOnlyAnErrorStatus(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/sk_live_'.str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2)));

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not publish its review of this head.']]], $this->github->writes);
        self::assertSame([], $this->ownerAsks());
    }

    public function testARetryWhoseProtectedPathsChangedPostsTheAskItRecords(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/old'));
        $roundId = $this->judgedRound(self::PULL, []);
        $this->github->refuseOnce('/statuses/'.SampleIdentity::HEAD, 502);
        $handler = $this->handler(new ConfiguredProjectModes([]));
        try {
            $handler(new PublishVerdict($roundId));
            self::fail('A refused status must fail the publication.');
        } catch (ForgeWriteFailed) {
        }
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/new'));

        $handler(new PublishVerdict($roundId));

        self::assertSame(['bin/new'], $this->listedPathsIn($this->askBodies()[1]));
        self::assertSame([['bin/new']], array_column($this->ownerAsks(), 'paths'));
    }

    public function testAnAskWhosePathsFillAPartToItsLastByteNeedsNoSecondPart(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/a', $this->pathFillingThePartWith(0)));

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertCount(1, $this->askBodies());
        self::assertSame(60000, strlen(substr($this->askBodies()[0], 0, (int) strrpos($this->askBodies()[0], "\n\n"))));
    }

    public function testAnAskWhosePathsPassAPartByOneByteGetsASecondPartWithTheLastPath(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/a', $this->pathFillingThePartWith(1)));

        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertCount(2, $this->askBodies());
        self::assertSame(['bin/a'], $this->listedPathsIn($this->askBodies()[0]));
    }

    public function testARetriedPublicationOfAProtectedHeadPostsTheAskOnceAndRecordsOneAsk(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));
        $roundId = $this->judgedRound(self::PULL, []);
        $this->github->refuseOnce('/statuses/'.SampleIdentity::HEAD, 502);
        $handler = $this->handler(new ConfiguredProjectModes([]));
        try {
            $handler(new PublishVerdict($roundId));
            self::fail('A refused status must fail the publication.');
        } catch (ForgeWriteFailed) {
        }

        $handler(new PublishVerdict($roundId));
        $handler(new PublishVerdict($roundId));

        self::assertSame([self::COMMENT, self::COMMENT, self::STATUS, self::STATUS, self::STATUS], array_column($this->github->writes, 0));
        self::assertCount(1, $this->ownerAsks());
    }

    public function testARetryThatFindsTheHeadProtectedAfterTheReviewCommentWasPostedStillPostsTheAsk(): void
    {
        $this->handOver('alpha', self::PULL);
        $roundId = $this->judgedRound(self::PULL, []);
        $this->github->refuseOnce('/statuses/'.SampleIdentity::HEAD, 502);
        $handler = $this->handler(new ConfiguredProjectModes([]));
        try {
            $handler(new PublishVerdict($roundId));
            self::fail('A refused status must fail the publication.');
        } catch (ForgeWriteFailed) {
        }
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));

        $handler(new PublishVerdict($roundId));

        self::assertSame([$this->approvedComment($roundId)], array_slice(array_column(array_column($this->github->writes, 1), 'body'), 0, 1));
        $ask = $this->ask('Protected paths:', '- <code>bin/check</code>');
        self::assertSame([$ask."\n\n".$this->askMarker($roundId, 1, $ask)], $this->askBodies());
        self::assertSame(['pending'], array_slice(array_column(array_column($this->github->writes, 1), 'state'), -1));
        self::assertCount(1, $this->ownerAsks());
    }

    public function testAVerdictTheForgePublishedIsRecordedOnTheRoundAtThePublicationTime(): void
    {
        $this->handOver('alpha', self::PULL);

        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertEquals(new DateTimeImmutable('2026-10-30 12:00:00', new DateTimeZone('UTC')), $this->fixture->transactions()->stored($roundId)->publishedAt());
    }

    public function testAVerdictTheForgeDidNotPublishLeavesTheRoundUnpublished(): void
    {
        $this->handOver('alpha', self::PULL);
        $roundId = $this->judgedRound(self::PULL, []);
        $forge = self::createStub(ForgeWrites::class);
        $forge->method('publishVerdict')->willReturn(false);

        $this->handlerOver($forge, new ConfiguredProjectModes([]))(new PublishVerdict($roundId));

        self::assertNull($this->fixture->transactions()->stored($roundId)->publishedAt());
    }

    public function testAnErrorStatusPublishedInPlaceOfAVerdictThatHoldsASecretLeavesTheRoundUnpublished(): void
    {
        $roundId = $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->placed('F1', Severity::P1, 'The fixture holds a key: sk_live_'.str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2)))]);

        self::assertNull($this->fixture->transactions()->stored($roundId)->publishedAt());
    }

    public function testAnErrorStatusWithoutAVerdictLeavesABlockedRoundInReview(): void
    {
        $blocked = new Verdict(Decision::Blocked, [], [], []);

        $roundId = $this->publishRoundJudgedOn(['Look at part 1.' => $blocked, 'Look at part 2.' => $blocked]);

        $this->assertStaysInReviewAfterItsErrorStatus($roundId);
    }

    public function testAReplayErrorWithoutAVerdictLeavesTheRoundInReview(): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay('alpha'));

        $roundId = $this->publishedApprovalAt(SampleIdentity::HEAD);

        $this->assertStaysInReviewAfterItsErrorStatus($roundId);
    }

    public function testAJudgedRoundWhosePublicationIsSkippedStaysUnpublished(): void
    {
        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertNull($this->fixture->transactions()->stored($roundId)->publishedAt());
    }

    public function testARoundOfALocalBranchStaysUnpublished(): void
    {
        $roundId = $this->judgedRound(RoundFixture::CHANGE, []);

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));

        self::assertNull($this->fixture->transactions()->stored($roundId)->publishedAt());
        self::assertSame(['warning'], array_column($this->fixture->log->cleanLogs(), 0));
    }

    public function testAPublicationTheForgeRefusedForMessengerToRetryLeavesTheRoundUnpublished(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);
        $this->github->refuseOnce('/statuses/'.SampleIdentity::HEAD, 422);

        try {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));
            self::fail('The refused write was not reported.');
        } catch (ForgeWriteFailed) {
            self::assertNull($this->fixture->transactions()->stored($roundId)->publishedAt());
        }
    }

    public function testAnOwnerAskTheForgeDidNotPublishIsNotRecorded(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));
        $roundId = $this->judgedRound(self::PULL, []);
        $forge = self::createStub(ForgeWrites::class);
        $forge->method('publishVerdict')->willReturn(false);

        $this->handlerOver($forge, new ConfiguredProjectModes([]))(new PublishVerdict($roundId));

        self::assertSame([], $this->ownerAsks());
    }

    public function testASecondRoundOfTheSameProtectedHeadRecordsNoSecondAsk(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));

        $first = $this->judgedApprovalAt(SampleIdentity::HEAD);
        $this->handler(new ConfiguredProjectModes([]))(new PublishVerdict($first));
        $second = $this->judgedApprovalAt(SampleIdentity::HEAD);
        $this->handler(new ConfiguredProjectModes([]))(new PublishVerdict($second));

        self::assertSame([$first], array_column($this->ownerAsks(), 'round'));
    }

    public function testARoundThatNamesTheSamePullRequestInAnotherLetterCaseRecordsNoSecondAsk(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));

        $first = $this->judgedApprovalAt(SampleIdentity::HEAD);
        $this->handler(new ConfiguredProjectModes([]))(new PublishVerdict($first));
        $second = $this->judgedApprovalAt(SampleIdentity::HEAD, 'https://github.com/Acme/Widgets/pull/7');
        $this->handler(new ConfiguredProjectModes([]))(new PublishVerdict($second));

        self::assertSame([$first], array_column($this->ownerAsks(), 'round'));
    }

    public function testAPublicationIsRefusedWhileTheSamePullRequestInAnotherLetterCaseIsBeingPublished(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);

        $this->expectException(PublicationBusy::class);

        new ChangePublications($this->locks)->whileHeld('alpha', 'https://github.com/Acme/Widgets/pull/7', fn () => $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId)));
    }

    public function testAHeadJudgedBeforeItsPullRequestWasHandedToIntaktIsPublishedOnceTheWatcherRecognizesIt(): void
    {
        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));
        self::assertSame([], $this->github->writes);

        $this->recognize(self::PULL, SampleIdentity::HEAD);

        $publications = $this->publicationsOf($roundId);
        self::assertCount(2, $publications);
        $this->handler(new ConfiguredProjectModes([]))($publications[1]);
        self::assertSame(['success'], array_column(array_column($this->github->writes, 1), 'state'));
    }

    public function testAnUnchangedHeadJudgedWhileItsPullRequestWasHandedOverBeforePublicationsWereQueuedIsPublishedOnTheNextTick(): void
    {
        $roundId = $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));
        self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::ChangeHandedToIntakt, json_encode(['project' => 'alpha', 'change' => self::PULL, 'head' => SampleIdentity::HEAD], JSON_THROW_ON_ERROR)))->succeeded());

        $this->recognize(self::PULL, SampleIdentity::HEAD);

        $publications = $this->publicationsOf($roundId);
        self::assertCount(2, $publications);
        $this->handler(new ConfiguredProjectModes([]))($publications[1]);
        self::assertSame(['success'], array_column(array_column($this->github->writes, 1), 'state'));
    }

    public function testRecognizingAPullRequestLeavesTheLookupOfItsJudgedRoundToAWorkerAfterTheTickCommits(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);
        self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::ChangeOpened, json_encode(['project' => 'alpha', 'change' => self::PULL, 'opening' => 'created'], JSON_THROW_ON_ERROR)))->succeeded());

        $this->recognizer()->recognizeAmong('alpha', [new OpenChange(new ChangeId(self::PULL), new CommitId(SampleIdentity::HEAD), $this->fixture->clock->now())]);

        self::assertCount(1, $this->publicationsOf($roundId));
        self::assertEquals([new PublishHandedOverChange('alpha', self::PULL, SampleIdentity::HEAD)], $this->queuedHandOvers());
    }

    public function testRecognizingAPullRequestWithoutAJudgedRoundQueuesNoPublication(): void
    {
        $roundId = $this->reviewedRound(self::PULL, []);

        $this->recognize(self::PULL, SampleIdentity::HEAD);

        self::assertSame([], $this->publicationsOf($roundId));
    }

    public function testRecognizingAPullRequestQueuesOnlyTheRoundOfTheHeadItIsListedAt(): void
    {
        $first = $this->judgedApprovalAt(SampleIdentity::HEAD);
        $second = $this->judgedApprovalAt(SampleIdentity::NEW_HEAD);

        // Listed in another letter case, because the forge may spell the repository other than it was configured.
        $this->recognize('https://github.com/Acme/Widgets/pull/7', SampleIdentity::NEW_HEAD);

        self::assertSame([1, 2], [count($this->publicationsOf($first)), count($this->publicationsOf($second))]);
    }

    public function testRecognizingAPullRequestQueuesTheRoundOfItsListedHeadThoughAnotherSpellingNumbersItsRoundsHigher(): void
    {
        $this->judgedApprovalAt(SampleIdentity::HEAD);
        $older = $this->judgedApprovalAt(str_repeat('b', 40));
        $current = $this->judgedApprovalAt(SampleIdentity::NEW_HEAD, 'https://github.com/Acme/Widgets/pull/7');

        $this->recognize(self::PULL, SampleIdentity::NEW_HEAD);

        self::assertSame([1, 2], [count($this->publicationsOf($older)), count($this->publicationsOf($current))]);
    }

    public function testRecognizingAPullRequestAtAHeadNotYetJudgedQueuesNoPublication(): void
    {
        $roundId = $this->judgedApprovalAt(SampleIdentity::HEAD);

        $this->recognize(self::PULL, SampleIdentity::NEW_HEAD);

        self::assertCount(1, $this->publicationsOf($roundId));
    }

    public function testAHandedOverProtectedHeadWithChangesAsksNoOwner(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));

        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->placed('A', Severity::P2, 'Fix the guard.'))], new ConfiguredProjectModes([]));

        self::assertSame(['failure'], array_column(array_column(array_slice($this->github->writes, 1), 1), 'state'));
        self::assertStringNotContainsString('Waiting for the owner', $this->commentBody());
        self::assertSame([], $this->ownerAsks());
    }

    public function testAProtectedHeadThatIsNotHandedToIntaktIsPublishedForTheLoopWithoutAnAsk(): void
    {
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));

        $this->publishRoundJudgedOn([]);

        self::assertSame(['success'], array_column(array_column(array_slice($this->github->writes, 1), 1), 'state'));
        self::assertSame([], $this->ownerAsks());
    }

    public function testAnAskTheLedgerRefusesFailsThePublicationAfterTheForgeShowsIt(): void
    {
        $this->handOver('alpha', self::PULL);
        $this->protectedChanges = ProtectedChanges::touching(new Paths('bin/check'));
        $roundId = $this->judgedRound(self::PULL, []);

        try {
            $this->handler(new ConfiguredProjectModes([]), new AppendRefusingLedger($this->fixture->ledger()))(new PublishVerdict($roundId));
            self::fail('A refused ask must fail the publication.');
        } catch (OwnerAskNotRecorded $refused) {
            self::assertSame('The owner ask for head '.SampleIdentity::HEAD.' could not be recorded: disk full', $refused->getMessage());
        }

        self::assertSame(['pending'], array_column(array_column(array_slice($this->github->writes, 1), 1), 'state'));
    }

    public function testAnApprovedHeadGetsTheCommentThenASuccessStatus(): void
    {
        $roundId = $this->publishRoundJudgedOn([]);

        self::assertSame([
            [self::COMMENT, ['body' => self::HEADING."\n\n```json\n".'{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 0."}'."\n```\n\n".$this->marker($roundId)]],
            [self::STATUS, ['state' => 'success', 'context' => 'intakt/review', 'description' => 'Intakt approves this head.']],
        ], $this->github->writes);
    }

    public function testAReviewerWhoseOnlyFindingIsADeferredP3IsPublishedAsApprovingWithTheSameEmptyActListAsTheReviewCommand(): void
    {
        $roundId = $this->publishRoundDeferringEveryP3On(['Look at part 1.' => $this->changes($this->unplaced('A', Severity::P3, 'Name the helper.'))]);

        self::assertSame([
            [self::COMMENT, ['body' => self::HEADING."\n\n```json\n".'{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 0."}'."\n```\n\n".$this->marker($roundId)]],
            [self::STATUS, ['state' => 'success', 'context' => 'intakt/review', 'description' => 'Intakt approves this head.']],
        ], $this->github->writes);
    }

    public function testAReviewerWhoseP2SitsBesideADeferredP3IsPublishedAsChangesNamingTheP2(): void
    {
        $this->publishRoundDeferringEveryP3On(['Look at part 1.' => $this->changes($this->placed('A', Severity::P2, 'Fix the guard.'), $this->unplaced('B', Severity::P3, 'Name the helper.'))]);

        self::assertStringContainsString("[P2] src/Thing.php:3: Fix the guard.\n", $this->commentBody());
        self::assertStringNotContainsString('Name the helper.', $this->commentBody());
        self::assertStringContainsString('"summary":"1 of 2 reviewers approve; findings to act on: 1."', $this->commentBody());
    }

    public function testAnApprovalWhoseActListHoldsAP2FilesItAsAFollowUpSoTheLoopReadsAnApprovalToo(): void
    {
        $roundId = $this->publishRoundJudgedOn(['Look at part 1.' => $this->approval($this->placed('F1', Severity::P2, 'Fix the guard.'))]);

        self::assertSame([
            [self::COMMENT, ['body' => implode("\n", [
                self::HEADING,
                '',
                'Follow-ups:',
                '[P2] src/Thing.php:3: Fix the guard.',
                '',
                '```json',
                '{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 1."}',
                '```',
                '',
                $this->marker($roundId),
            ])]],
            [self::STATUS, ['state' => 'success', 'context' => 'intakt/review', 'description' => 'Intakt approves this head.']],
        ], $this->github->writes);
        self::assertSame(LoopVerdict::Approve, $this->loopReadingOfTheComment());
    }

    public function testAnApprovalWhoseActListHoldsAP1IsPublishedAsChangesInTheStatusAndTheComment(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->approval($this->placed('F1', Severity::P1, 'The minority reviewer found a bug.'))]);

        self::assertSame(['state' => 'failure', 'context' => 'intakt/review', 'description' => 'Intakt asks for changes on this head.'], $this->github->writes[1][1]);
        self::assertStringContainsString('{"verdict":"changes","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 1."}', $this->commentBody());
        self::assertSame(LoopVerdict::Changes, $this->loopReadingOfTheComment());
    }

    public function testACommentForAnApprovalThatListsAP1DeclaresTheVerdictTheLoopReads(): void
    {
        $comment = new LoopReviewComment(new CommitId(SampleIdentity::HEAD), Decision::Approve, [$this->placed('F1', Severity::P1, 'The minority reviewer found a bug.')], '2 of 3 reviewers approve; findings to act on: 1.');

        $reviews = new LoopReviewReader()->read('intakt', SampleIdentity::HEAD, [new PullRequestComment('intakt', SampleIdentity::HEAD, $comment->text())]);

        self::assertSame([Decision::Changes, LoopVerdict::Changes], [$comment->verdict(), $reviews->reviews[0]->verdict]);
    }

    public function testARetryAfterARefusedStatusPostsTheStatusWithoutASecondComment(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);
        $this->github->refuseOnce('/statuses/'.SampleIdentity::HEAD, 502);
        $handler = $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        try {
            $handler(new PublishVerdict($roundId));
            self::fail('A refused status must fail the publication.');
        } catch (ForgeWriteFailed) {
        }

        $handler(new PublishVerdict($roundId));

        self::assertSame([self::COMMENT, self::STATUS, self::STATUS], array_column($this->github->writes, 0));
    }

    public function testAHeadWithChangesGetsAFailureStatusAndOneLinePerFindingToActOn(): void
    {
        $roundId = $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes(
            $this->placed('F1', Severity::P2, "The new line drops\nthe old guard."),
            $this->unplaced('F2', Severity::P1, 'The task asks for a test.'),
            new Finding('F3', Severity::P2, 'README.md', null, null, 'The guide still names the old flag.', FixableBy::Builder, false, null, null),
        )]);

        self::assertSame([
            [self::COMMENT, ['body' => implode("\n", [
                self::HEADING,
                '',
                '[P2] src/Thing.php:3: The new line drops the old guard.',
                '[P1] (no file): The task asks for a test.',
                '[P2] README.md: The guide still names the old flag.',
                '',
                '```json',
                '{"verdict":"changes","head":"'.SampleIdentity::HEAD.'","summary":"1 of 2 reviewers approve; findings to act on: 3."}',
                '```',
                '',
                $this->marker($roundId),
            ])]],
            [self::STATUS, ['state' => 'failure', 'context' => 'intakt/review', 'description' => 'Intakt asks for changes on this head.']],
        ], $this->github->writes);
    }

    public function testTwoReviewersReportingOnePlaceArePublishedAsOneFindingToActOn(): void
    {
        $this->publishRoundJudgedOn([
            'Look at part 1.' => $this->changes($this->placed('F1', Severity::P1, 'The guard is gone.')),
            'Look at part 2.' => $this->changes($this->placed('F2', Severity::P2, 'The guard is missing.')),
        ]);

        $body = $this->github->writes[0][1]['body'];
        self::assertIsString($body);
        self::assertSame(1, substr_count($body, 'src/Thing.php:3:'));
        self::assertStringContainsString('findings to act on: 1."', $body);
    }

    public function testTheLoopsReaderReadsThePublishedCommentAsTheVerdictOfThatHead(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->placed('F1', Severity::P2, 'The new line drops the old guard.'))]);

        $reviews = new LoopReviewReader()->read('intakt', SampleIdentity::HEAD, [new PullRequestComment('intakt', SampleIdentity::HEAD, $this->commentBody())]);

        self::assertSame(LoopVerdict::Changes, $reviews->reviews[0]->verdict);
        self::assertSame(['src/Thing.php', 3, Severity::P2], [$reviews->reviews[0]->findings[0]->file, $reviews->reviews[0]->findings[0]->line, $reviews->reviews[0]->findings[0]->severity]);
    }

    public function testAnApprovedRoundWhoseHeadLaterFailedToBootIsPublishedAsAFailureWithoutAComment(): void
    {
        $handler = $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $roundId = $this->judgedRound(self::PULL, []);
        $this->markStale($roundId);

        foreach ($this->publicationsOf($roundId) as $publication) {
            $handler($publication);
        }

        self::assertSame([[self::STATUS, self::WITHDRAWN]], array_unique($this->github->writes, SORT_REGULAR));
    }

    public function testAnApprovalAlreadyOnTheForgeIsWithdrawnOnceItsHeadFailsToBoot(): void
    {
        $roundId = $this->publishRoundJudgedOn([]);
        $alreadyHandled = count($this->publicationsOf($roundId));

        $this->markStale($roundId);
        foreach (array_slice($this->publicationsOf($roundId), $alreadyHandled) as $publication) {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))($publication);
        }

        self::assertSame([self::STATUS, self::WITHDRAWN], array_last($this->github->writes));
    }

    public function testAStaleMarkThatLandsWhileTheApprovalIsOnTheWayIsWithdrawnByThatSamePublication(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);
        $published = [];
        $forge = self::createStub(ForgeWrites::class);
        $forge->method('publishVerdict')->willReturnCallback(function (string $project, ChangeId $change, PublishedVerdict $verdict) use ($roundId, &$published): bool {
            // Marked here, because only a mark that commits after the verdict was decided and before its status is written forces the race.
            if ([] === $published) {
                $this->markStale($roundId);
            }

            $published[] = [$verdict->status, $verdict->description];

            return true;
        });

        $this->handlerOver($forge, new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));

        self::assertSame([HeadStatus::Failure, 'Intakt withdrew its review: this head does not boot.'], array_last($published));
    }

    public function testAReturningHeadThatFailsToBootHasItsApprovalWithdrawnThoughARoundOfAnotherHeadWasJudgedMeanwhile(): void
    {
        $first = $this->publishedApprovalAt(SampleIdentity::HEAD);
        $this->publishedApprovalAt(SampleIdentity::NEW_HEAD);

        $this->publishStaleMarkOf($first);

        self::assertSame(self::WITHDRAWN, array_last($this->statusesOn(SampleIdentity::HEAD)));
    }

    public function testAWithdrawalWritesNothingOnceALaterRoundOfTheSameHeadWasJudged(): void
    {
        $first = $this->publishedApprovalAt(SampleIdentity::HEAD);
        $this->publishedApprovalAt(SampleIdentity::HEAD);
        $writes = $this->github->writes;

        $this->publishStaleMarkOf($first);

        self::assertSame($writes, $this->github->writes);
    }

    public function testAnApprovalStillOnTheWayWhenALaterRoundOfItsHeadIsJudgedAndWithdrawnLeavesTheWithdrawalAsTheLastStatus(): void
    {
        $first = $this->judgedApprovalAt(SampleIdentity::HEAD);
        $raced = false;
        $busy = [];
        $published = [];
        $forge = self::createStub(ForgeWrites::class);
        $forge->method('publishVerdict')->willReturnCallback(function (string $project, ChangeId $change, PublishedVerdict $verdict) use (&$raced, &$busy, &$published, &$forge): bool {
            // Judged, marked and delivered here, because only a replacement withdrawn while the first approval is on the way forces the race.
            if (!$raced) {
                $raced = true;
                $replacement = $this->judgedApprovalAt(SampleIdentity::HEAD);
                $this->markStale($replacement);
                $busy = $this->deliverEach($this->publicationsOf($replacement), $forge);
            }

            $published[] = [$verdict->status, $verdict->description];

            return true;
        });

        $this->deliverEach($this->publicationsOf($first), $forge);
        $this->deliverEach($busy, $forge);

        self::assertSame([HeadStatus::Failure, 'Intakt withdrew its review: this head does not boot.'], array_last($published));
        self::assertNotSame([], $busy);
    }

    public function testAPublicationOfAChangeWhoseLockIsHeldIsRefusedForMessengerToBringBack(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);
        $refused = null;

        new ChangePublications($this->locks)->whileHeld('alpha', self::PULL, function () use ($roundId, &$refused): void {
            try {
                $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));
            } catch (PublicationBusy $busy) {
                $refused = $busy;
            }
        });

        self::assertInstanceOf(PublicationBusy::class, $refused);
        self::assertTrue($refused->forceRetry());
        self::assertSame([], $this->github->writes);
    }

    public function testAPublicationOfAnotherChangeIsNotHeldUpByAChangeBeingPublished(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);

        new ChangePublications($this->locks)->whileHeld(
            'alpha',
            'https://github.com/acme/widgets/pull/8',
            fn () => $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId)),
        );

        self::assertSame(['success'], array_column(array_column($this->github->writes, 1), 'state'));
    }

    public function testAHeadNoReviewerCouldReviewGetsAnErrorStatusAndNoComment(): void
    {
        $blocked = new Verdict(Decision::Blocked, [], [], []);

        $this->publishRoundJudgedOn(['Look at part 1.' => $blocked, 'Look at part 2.' => $blocked]);

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not review this head.']]], $this->github->writes);
    }

    public function testARoundWhoseJudgingFailedForGoodGetsAnErrorStatusAndNoComment(): void
    {
        $roundId = $this->reviewedRound(self::PULL, []);

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not review this head.']]], $this->github->writes);
    }

    public function testARoundWhoseReviewerMessagesWereAllParkedGetsAnErrorStatusOnceJudged(): void
    {
        $roundId = $this->fixture->startFor(self::PULL, $this->fixture->bus, 2);
        foreach ($this->fixture->bus->messages as $message) {
            ($this->fixture->failedMessages())(new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('gave up')));
        }

        foreach ($this->fixture->judgings() as $judging) {
            $this->fixture->judge(new VerdictPerRole([]))($judging);
        }

        foreach ($this->publicationsOf($roundId) as $publication) {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))($publication);
        }

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not review this head.']]], $this->github->writes);
    }

    public function testACommentThatStillHoldsASecretAfterRedactionPublishesOnlyAnErrorStatus(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->placed('F1', Severity::P1, 'The fixture holds a key: sk_live_'.str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2)))]);

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not publish its review of this head.']]], $this->github->writes);
    }

    public function testAnUnclosedCredentialListInAFindingIsRedactedOnItsLineAndTheVerdictBlockIsPublished(): void
    {
        $roundId = $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->placed('F1', Severity::P2, 'The test sends Authorization: [ without closing it.'))]);

        self::assertSame([
            [self::COMMENT, ['body' => implode("\n", [
                self::HEADING,
                '',
                '[P2] src/Thing.php:3: The test sends Authorization: [redacted]',
                '',
                '```json',
                '{"verdict":"changes","head":"'.SampleIdentity::HEAD.'","summary":"1 of 2 reviewers approve; findings to act on: 1."}',
                '```',
                '',
                $this->marker($roundId),
            ])]],
            [self::STATUS, ['state' => 'failure', 'context' => 'intakt/review', 'description' => 'Intakt asks for changes on this head.']],
        ], $this->github->writes);
    }

    public function testAnApprovedRoundOfAReplayProjectGetsAnErrorStatusAndNoComment(): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay('alpha'));

        $this->publishRoundJudgedOn([]);

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt does not count a replay as a review of this head.']]], $this->github->writes);
    }

    // A replay was never published as a review, so it has nothing to withdraw and keeps the one status every replay gets.
    public function testAStaleRoundOfAReplayProjectGetsTheReplayErrorStatusAndNoComment(): void
    {
        new FlagProjectAsReplayHandler($this->fixture->entities)(new FlagProjectAsReplay('alpha'));
        $handler = $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $roundId = $this->judgedRound(self::PULL, []);
        $this->markStale($roundId);

        foreach ($this->publicationsOf($roundId) as $publication) {
            $handler($publication);
        }

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt does not count a replay as a review of this head.']]], array_unique($this->github->writes, SORT_REGULAR));
    }

    public function testALiveProjectsJudgedRoundPublishesNothing(): void
    {
        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame([], $this->github->writes);
    }

    public function testARoundReviewedInLiveModeIsPublishedAsItsVerdictAfterTheProjectSwitchesToReviewerForLoop(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes([]));
        $roundId = $this->judgedRound(self::PULL, []);

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));

        self::assertSame(['success'], array_column(array_column($this->github->writes, 1), 'state'));
    }

    public function testARoundReviewedForTheLoopIsStillPublishedAsItsVerdict(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));

        self::assertSame(['success'], array_column(array_column($this->github->writes, 1), 'state'));
    }

    public function testAJudgedRoundIsQueuedForPublicationOnceEvenWhenItsJudgingIsDeliveredAgain(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);

        $this->fixture->judge(new VerdictPerRole([]))($this->fixture->judgings()[0]);

        self::assertEquals([new PublishVerdict($roundId)], $this->publicationsOf($roundId));
    }

    public function testARoundOfALocalBranchPublishesNothingAndSaysWhy(): void
    {
        $roundId = $this->judgedRound(RoundFixture::CHANGE, []);

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));

        self::assertSame([], $this->github->writes);
        self::assertSame(['warning'], array_column($this->fixture->log->cleanLogs(), 0));
    }

    public function testAStatusGitHubRefusesFailsThePublicationForMessengerToRetry(): void
    {
        $roundId = $this->judgedRound(self::PULL, []);
        $this->github->refuseOnce('/statuses/'.SampleIdentity::HEAD, 422);

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered 422 to a write.'));

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $this->github = new ScriptedGitHub();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->protectedChanges = ProtectedChanges::touching(new Paths());
    }

    // The application's command bus, so the publication the mark queues lands on the application's queue.
    private function markStale(string $roundId): void
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);

        new MarkVerdictsStaleHandler($this->fixture->transactions(), $commands)(new MarkVerdictsStale($roundId));
    }

    // The error status is asserted too, so the round is known to be unpublished although the forge took a write for it.
    private function assertStaysInReviewAfterItsErrorStatus(string $roundId): void
    {
        $round = $this->fixture->transactions()->stored($roundId);

        self::assertSame(['error'], array_slice(array_column(array_column($this->github->writes, 1), 'state'), -1));
        self::assertNull($round->publishedAt());
        self::assertSame(ReviewStatus::InReview, new StoredLatestRoundStatus($this->fixture->transactions())->of($round->project(), $round->change()));
    }

    private function publishedApprovalAt(string $head): string
    {
        $roundId = $this->judgedApprovalAt($head);
        foreach ($this->publicationsOf($roundId) as $publication) {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))($publication);
        }

        return $roundId;
    }

    // Only the messages of this round, so an earlier round of the change is not delivered or judged again.
    private function judgedApprovalAt(string $head, string $change = self::PULL): string
    {
        $vendor = new VerdictPerRole([]);
        $sent = count($this->fixture->bus->messages);
        $judged = count($this->fixture->judgings());
        $roundId = $this->fixture->startAtHead($change, $head);
        foreach (array_slice($this->fixture->bus->messages, $sent) as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        foreach (array_slice($this->fixture->judgings(), $judged) as $judging) {
            $this->fixture->judge($vendor)($judging);
        }

        return $roundId;
    }

    // A refused delivery is kept, as Messenger keeps a message whose handler threw a recoverable failure, so the test brings it back when the race allows.
    /**
     * @param list<PublishVerdict> $publications
     *
     * @return list<PublishVerdict>
     */
    private function deliverEach(array $publications, ForgeWrites $forge): array
    {
        $handler = $this->handlerOver($forge, new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $refused = [];

        foreach ($publications as $publication) {
            try {
                $handler($publication);
            } catch (PublicationBusy) {
                $refused[] = $publication;
            }
        }

        return $refused;
    }

    private function publishStaleMarkOf(string $roundId): void
    {
        $alreadyHandled = count($this->publicationsOf($roundId));
        $this->markStale($roundId);

        foreach (array_slice($this->publicationsOf($roundId), $alreadyHandled) as $publication) {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))($publication);
        }
    }

    /**
     * @return list<array<mixed>>
     */
    private function statusesOn(string $head): array
    {
        $statuses = array_filter($this->github->writes, static fn (array $write): bool => 'POST '.self::API.'/statuses/'.$head === $write[0]);

        return array_column($statuses, 1);
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function judgedRound(string $change, array $verdicts): string
    {
        $vendor = new VerdictPerRole($verdicts);
        $roundId = $this->reviewedRound($change, $verdicts);
        foreach ($this->fixture->judgings() as $judging) {
            $this->fixture->judge($vendor)($judging);
        }

        return $roundId;
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function reviewedRound(string $change, array $verdicts): string
    {
        $vendor = new VerdictPerRole($verdicts);
        $roundId = $this->fixture->startFor($change, $this->fixture->bus, 2);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $roundId;
    }

    private function handler(ConfiguredProjectModes $modes, ?Ledger $askLedger = null): PublishVerdictHandler
    {
        return $this->handlerOver($this->github->forge(), $modes, $askLedger);
    }

    private function handlerOver(ForgeWrites $forge, ConfiguredProjectModes $modes, ?Ledger $askLedger = null): PublishVerdictHandler
    {
        $handed = new HandedOverChanges($this->fixture->ledger());
        $asks = new OwnerAsks($handed, $this->protectedHeads(), $askLedger ?? $this->fixture->ledger());

        return new PublishVerdictHandler($this->fixture->transactions(), $modes, $handed, $this->verdicts(), $asks, $forge, new ChangePublications($this->locks), $this->fixture->log, new RoundStatusMirror(new StoredLatestRoundStatus($this->fixture->transactions()), $this->locks, $this->fixture->log));
    }

    private function protectedHeads(): ProtectedHeads
    {
        $heads = self::createStub(ProtectedHeads::class);
        $heads->method('of')->willReturnCallback(fn (string $project, string $head): ProtectedChanges => $this->protectedChanges);

        return $heads;
    }

    private function handOver(string $project, string $change): void
    {
        self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::ChangeHandedToIntakt, json_encode(['project' => $project, 'change' => $change, 'head' => SampleIdentity::HEAD], JSON_THROW_ON_ERROR)))->succeeded());
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function publishRoundJudgedOn(array $verdicts, ?ConfiguredProjectModes $modes = null): string
    {
        $handler = $this->handler($modes ?? new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $roundId = $this->judgedRound(self::PULL, $verdicts);

        foreach ($this->publicationsOf($roundId) as $publication) {
            $handler($publication);
        }

        return $roundId;
    }

    private function marker(string $roundId): string
    {
        return '<!-- intakt-verdict: '.$roundId.' -->';
    }

    /**
     * @return list<array<mixed>>
     */
    private function ownerAsks(): array
    {
        $payloads = array_map(static fn (RecordedEvent $recorded): mixed => json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR), iterator_to_array($this->fixture->ledger()->readOfKind(LedgerEventKind::OwnerAsked), false));

        return array_values(array_filter($payloads, is_array(...)));
    }

    private function ask(string ...$lines): string
    {
        return implode("\n", ['**Waiting for the owner** (head '.SampleIdentity::HEAD.')', '', 'This head touches protected paths, so it merges only once the owner has approved this head.', '', ...$lines]);
    }

    /**
     * @return list<string>
     */
    private function askBodies(): array
    {
        $bodies = array_column(array_column($this->github->writes, 1), 'body');

        return array_values(array_filter($bodies, static fn (mixed $body): bool => is_string($body) && str_contains($body, '<!-- intakt-owner-ask: ')));
    }

    /**
     * @return list<string>
     */
    private function listedPaths(): array
    {
        return array_merge([], ...array_map($this->listedPathsIn(...), $this->askBodies()));
    }

    /**
     * @return list<string>
     */
    private function listedPathsIn(string $body): array
    {
        preg_match_all('~^- <code>(.*)</code>$~m', $body, $paths);

        return $paths[1];
    }

    // Sized from the ask's own lines, because the boundary tests need a first part that ends exactly $over bytes past its limit.
    private function pathFillingThePartWith(int $over): string
    {
        return str_repeat('p', 60000 - strlen($this->ask('Protected paths:')) - 1 - strlen('- <code>bin/a</code>') - 1 - strlen('- <code></code>') + $over);
    }

    // The lookup handled apart from the recognition, because the queue hands it to a worker only once the tick has committed.
    private function recognize(string $change, string $head): void
    {
        self::assertTrue($this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::ChangeOpened, json_encode(['project' => 'alpha', 'change' => self::PULL, 'opening' => 'created'], JSON_THROW_ON_ERROR)))->succeeded());
        $this->recognizer()->recognizeAmong('alpha', [new OpenChange(new ChangeId($change), new CommitId($head), $this->fixture->clock->now())]);

        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);
        $lookup = new PublishHandedOverChangeHandler($this->fixture->transactions(), $commands);
        foreach ($this->queuedHandOvers() as $handOver) {
            $lookup($handOver);
        }
    }

    // The application's queue, because the judge transition of the application's workflow queues the publication.
    /**
     * @return list<PublishVerdict>
     */
    private function publicationsOf(string $roundId): array
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);
        $sent = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $queue->getSent());

        return array_values(array_filter($sent, static fn (object $message): bool => $message instanceof PublishVerdict && $roundId === $message->roundId));
    }

    private function recognizer(): ChangesHandedToIntakt
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);

        return new ChangesHandedToIntakt($this->fixture->ledger(), new WatcherLedger($this->fixture->ledger()), self::createStub(ChangeAuthorship::class), $this->fixture->log, $commands);
    }

    /**
     * @return list<PublishHandedOverChange>
     */
    private function queuedHandOvers(): array
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        assert($queue instanceof InMemoryTransport);
        $sent = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $queue->getSent());

        return array_values(array_filter($sent, static fn (object $message): bool => $message instanceof PublishHandedOverChange));
    }

    private function approvedComment(string $roundId): string
    {
        return self::HEADING."\n\n```json\n".'{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 0."}'."\n```\n\n".$this->marker($roundId);
    }

    /**
     * @return non-empty-string
     */
    private function askMarker(string $roundId, int $part, string ...$asks): string
    {
        return '<!-- intakt-owner-ask: '.$roundId.' '.hash('sha256', json_encode($asks, JSON_THROW_ON_ERROR)).' part '.$part.' -->';
    }

    private function verdicts(): VerdictForTheLoop
    {
        $ledger = new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([]));

        return new VerdictForTheLoop(new StoredVerdicts(self::getContainer()->get('serializer.stored')), $this->fixture->findingStore(), $ledger, new ChangeDecision());
    }

    private function approval(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Approve, array_values($findings), [], []);
    }

    private function loopReadingOfTheComment(): LoopVerdict
    {
        return new LoopReviewReader()->read('intakt', SampleIdentity::HEAD, [new PullRequestComment('intakt', SampleIdentity::HEAD, $this->commentBody())])->reviews[0]->verdict;
    }

    private function placed(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, 'src/Thing.php', 3, null, $text, FixableBy::Builder, false, null, null);
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }

    private function commentBody(): string
    {
        $body = $this->github->writes[0][1]['body'] ?? null;

        return is_string($body) ? $body : self::fail('No comment was posted.');
    }

    private function unplaced(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, null, null, null, $text, FixableBy::Builder, false, null, null);
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function publishRoundDeferringEveryP3On(array $verdicts): string
    {
        $roundId = $this->reviewedRound(self::PULL, $verdicts);
        foreach ($this->fixture->judgings() as $judging) {
            $this->fixture->judge(new DeferringJudge(new VerdictPerRole($verdicts)))($judging);
        }

        foreach ($this->publicationsOf($roundId) as $publication) {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))($publication);
        }

        return $roundId;
    }
}
