<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\PublishVerdict\LoopReviewComment;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdictHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\QueuePublicationOfJudgedRound;
use Intakt\Core\Review\UseCase\PublishVerdict\QueuePublicationOfUnjudgedRound;
use Intakt\Core\Review\UseCase\PublishVerdict\VerdictForTheLoop;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Core\Shadow\Infrastructure\ModeGuardedForgeWrites;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Plugin\GitHubForge\ScriptedGitHub;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

// A real round on SQLite, judged by the application's workflow, published through the GitHub forge over a scripted GitHub, so the test sees the very requests the loop would read.
#[Group('persistence')]
#[CoversClass(PublishVerdictHandler::class)]
#[CoversClass(PublishVerdict::class)]
#[CoversClass(QueuePublicationOfJudgedRound::class)]
#[CoversClass(QueuePublicationOfUnjudgedRound::class)]
#[CoversClass(VerdictForTheLoop::class)]
#[CoversClass(LoopReviewComment::class)]
#[CoversClass(RoundLedgerReading::class)]
#[UsesClass(PublishedVerdict::class)]
#[UsesClass(HeadStatus::class)]
#[UsesClass(ModeGuardedForgeWrites::class)]
#[UsesClass(LoopReviewReader::class)]
#[UsesClass(PostedComments::class)]
#[UsesClass(VerdictLocks::class)]
#[UsesClass(ReviewToken::class)]
final class PublishVerdictTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string API = 'https://api.github.com/repos/acme/widgets';
    private const string STATUS = 'POST '.self::API.'/statuses/'.SampleIdentity::HEAD;
    private const string COMMENT = 'POST '.self::API.'/issues/7/comments';
    private const string HEADING = '**Review by Intakt** (head aaaaaaaaaaaa)';

    private RoundFixture $fixture;
    private ScriptedGitHub $github;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $this->github = new ScriptedGitHub();
    }

    public function testAnApprovedHeadGetsTheCommentThenASuccessStatus(): void
    {
        $roundId = $this->publishRoundJudgedOn([]);

        self::assertSame([
            [self::COMMENT, ['body' => self::HEADING."\n\n```json\n".'{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 0."}'."\n```\n\n".$this->marker($roundId)]],
            [self::STATUS, ['state' => 'success', 'context' => 'intakt/review', 'description' => 'Intakt approves this head.']],
        ], $this->github->writes);
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

    public function testTheLoopsReaderReadsThePublishedCommentAsTheVerdictOfThatHead(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->placed('F1', Severity::P2, 'The new line drops the old guard.'))]);

        $reviews = new LoopReviewReader()->read('intakt', SampleIdentity::HEAD, [new PullRequestComment('intakt', SampleIdentity::HEAD, $this->commentBody())]);

        self::assertSame(LoopVerdict::Changes, $reviews->reviews[0]->verdict);
        self::assertSame(['src/Thing.php', 3, Severity::P2], [$reviews->reviews[0]->findings[0]->file, $reviews->reviews[0]->findings[0]->line, $reviews->reviews[0]->findings[0]->severity]);
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

        $this->unjudgedPublications()(new WorkerMessageFailedEvent(new Envelope($this->fixture->judgings()[0]), 'async', new RuntimeException('tier not mapped')));
        foreach ($this->publicationsOf($roundId) as $publication) {
            $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))($publication);
        }

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not review this head.']]], $this->github->writes);
    }

    public function testAJudgingThatWillStillBeRetriedQueuesNoPublication(): void
    {
        $roundId = $this->reviewedRound(self::PULL, []);
        $event = new WorkerMessageFailedEvent(new Envelope($this->fixture->judgings()[0]), 'async', new RuntimeException('again'));
        $event->setForRetry();

        $this->unjudgedPublications()($event);

        self::assertSame([], $this->publicationsOf($roundId));
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

    public function testALiveProjectsJudgedRoundPublishesNothing(): void
    {
        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame([], $this->github->writes);
    }

    public function testAShadowProjectsVerdictIsStoppedByTheModeGuard(): void
    {
        $modes = new ConfiguredProjectModes(['alpha' => 'shadow']);
        $handlerModes = new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']);
        $handler = new PublishVerdictHandler($this->fixture->transactions(), $handlerModes, $this->verdicts(), new ModeGuardedForgeWrites($this->github->forge(), $modes), $this->fixture->log);

        foreach ($this->publicationsOf($this->judgedRound(self::PULL, [])) as $publication) {
            $handler($publication);
        }

        self::assertSame([], $this->github->writes);
    }

    public function testARoundReviewedInShadowModeIsPublishedAsItsVerdictAfterTheProjectSwitchesToReviewerForLoop(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'shadow']));
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

    private function commentBody(): string
    {
        $body = $this->github->writes[0][1]['body'] ?? null;

        return is_string($body) ? $body : self::fail('No comment was posted.');
    }

    private function loopReadingOfTheComment(): LoopVerdict
    {
        return new LoopReviewReader()->read('intakt', SampleIdentity::HEAD, [new PullRequestComment('intakt', SampleIdentity::HEAD, $this->commentBody())])->reviews[0]->verdict;
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

    // The application's listener, so the test sees it wired to the bus that queues on the application's transport.
    private function unjudgedPublications(): QueuePublicationOfUnjudgedRound
    {
        return self::getContainer()->get(QueuePublicationOfUnjudgedRound::class);
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

    private function handler(ConfiguredProjectModes $modes): PublishVerdictHandler
    {
        return new PublishVerdictHandler($this->fixture->transactions(), $modes, $this->verdicts(), new ModeGuardedForgeWrites($this->github->forge(), $modes), $this->fixture->log);
    }

    private function verdicts(): VerdictForTheLoop
    {
        $ledger = new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([]));

        return new VerdictForTheLoop(new StoredVerdicts(self::getContainer()->get('serializer.stored')), $this->fixture->findingStore(), $ledger, new ChangeDecision());
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }

    private function approval(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Approve, array_values($findings), [], []);
    }

    private function placed(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, 'src/Thing.php', 3, null, $text, FixableBy::Builder, false, null, null);
    }

    private function unplaced(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, null, null, null, $text, FixableBy::Builder, false, null, null);
    }
}
