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
use Intakt\Core\Review\UseCase\PublishVerdict\VerdictForTheLoop;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Core\Shadow\Infrastructure\ModeGuardedForgeWrites;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Plugin\GitHubForge\ScriptedGitHub;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

// A real round on SQLite, judged by the application's workflow, published through the GitHub forge over a scripted GitHub, so the test sees the very requests the loop would read.
#[Group('persistence')]
#[CoversClass(PublishVerdictHandler::class)]
#[CoversClass(PublishVerdict::class)]
#[CoversClass(QueuePublicationOfJudgedRound::class)]
#[CoversClass(VerdictForTheLoop::class)]
#[CoversClass(LoopReviewComment::class)]
#[CoversClass(RoundLedgerReading::class)]
#[UsesClass(PublishedVerdict::class)]
#[UsesClass(HeadStatus::class)]
#[UsesClass(ModeGuardedForgeWrites::class)]
#[UsesClass(LoopReviewReader::class)]
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
        $this->publishRoundJudgedOn([]);

        self::assertSame([
            [self::COMMENT, ['body' => self::HEADING."\n\n```json\n".'{"verdict":"approve","head":"'.SampleIdentity::HEAD.'","summary":"2 of 2 reviewers approve; findings to act on: 0."}'."\n```"]],
            [self::STATUS, ['state' => 'success', 'context' => 'intakt/review', 'description' => 'Intakt approves this head.']],
        ], $this->github->writes);
    }

    public function testAHeadWithChangesGetsAFailureStatusAndOneLinePerFindingToActOn(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes(
            $this->finding('F1', Severity::P2, 'src/Thing.php', 3, "The new line drops\nthe old guard."),
            $this->finding('F2', Severity::P1, null, null, 'The task asks for a test.'),
        )]);

        self::assertSame([
            [self::COMMENT, ['body' => implode("\n", [
                self::HEADING,
                '',
                '[P2] src/Thing.php:3: The new line drops the old guard.',
                '[P1] (no file): The task asks for a test.',
                '',
                '```json',
                '{"verdict":"changes","head":"'.SampleIdentity::HEAD.'","summary":"1 of 2 reviewers approve; findings to act on: 2."}',
                '```',
            ])]],
            [self::STATUS, ['state' => 'failure', 'context' => 'intakt/review', 'description' => 'Intakt asks for changes on this head.']],
        ], $this->github->writes);
    }

    public function testTheLoopsReaderReadsThePublishedCommentAsTheVerdictOfThatHead(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->finding('F1', Severity::P2, 'src/Thing.php', 3, 'The new line drops the old guard.'))]);
        $body = $this->github->writes[0][1]['body'] ?? '';
        self::assertIsString($body);

        $reviews = new LoopReviewReader()->read('intakt', SampleIdentity::HEAD, [new PullRequestComment('intakt', SampleIdentity::HEAD, $body)]);

        self::assertSame(LoopVerdict::Changes, $reviews->reviews[0]->verdict);
        self::assertSame(['src/Thing.php', 3, Severity::P2], [$reviews->reviews[0]->findings[0]->file, $reviews->reviews[0]->findings[0]->line, $reviews->reviews[0]->findings[0]->severity]);
    }

    public function testAHeadNoReviewerCouldReviewGetsAnErrorStatusAndNoComment(): void
    {
        $blocked = new Verdict(Decision::Blocked, [], [], []);

        $this->publishRoundJudgedOn(['Look at part 1.' => $blocked, 'Look at part 2.' => $blocked]);

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not review this head.']]], $this->github->writes);
    }

    public function testACommentThatStillHoldsASecretAfterRedactionPublishesOnlyAnErrorStatus(): void
    {
        $this->publishRoundJudgedOn(['Look at part 1.' => $this->changes($this->finding('F1', Severity::P1, 'src/Thing.php', 3, 'The fixture holds a key: sk_live_'.str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2)))]);

        self::assertSame([[self::STATUS, ['state' => 'error', 'context' => 'intakt/review', 'description' => 'Intakt could not publish its review of this head.']]], $this->github->writes);
    }

    public function testALiveProjectsJudgedRoundPublishesNothing(): void
    {
        $this->publishRoundJudgedOn([], new ConfiguredProjectModes([]));

        self::assertSame([], $this->github->writes);
    }

    public function testAShadowProjectsVerdictIsStoppedByTheModeGuard(): void
    {
        $modes = new ConfiguredProjectModes(['alpha' => 'shadow']);
        $handler = new PublishVerdictHandler($this->fixture->transactions(), new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']), $this->verdicts(), new ModeGuardedForgeWrites($this->github->forge(), $modes), $this->fixture->log);

        foreach ($this->publicationsOf($this->judgedRound(self::PULL, [])) as $publication) {
            $handler($publication);
        }

        self::assertSame([], $this->github->writes);
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
        $this->github->refuse('/statuses/'.SampleIdentity::HEAD, 422);

        $this->expectExceptionMessage('GitHub answered 422 to a write.');

        $this->handler(new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']))(new PublishVerdict($roundId));
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function publishRoundJudgedOn(array $verdicts, ?ConfiguredProjectModes $modes = null): void
    {
        $handler = $this->handler($modes ?? new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));

        foreach ($this->publicationsOf($this->judgedRound(self::PULL, $verdicts)) as $publication) {
            $handler($publication);
        }
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function judgedRound(string $change, array $verdicts): string
    {
        $vendor = new VerdictPerRole($verdicts);
        $roundId = $this->fixture->startFor($change, $this->fixture->bus, 2);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        foreach ($this->fixture->judgings() as $judging) {
            $this->fixture->judge($vendor)($judging);
        }

        return $roundId;
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

    private function finding(string $id, Severity $severity, ?string $file, ?int $line, string $text): Finding
    {
        return new Finding($id, $severity, $file, $line, null, $text, FixableBy::Builder, false, null, null);
    }
}
