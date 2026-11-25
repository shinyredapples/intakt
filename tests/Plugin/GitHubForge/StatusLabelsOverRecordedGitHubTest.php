<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\ProtectedHeads;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\PublishVerdict\ChangePublications;
use Intakt\Core\Review\UseCase\PublishVerdict\OwnerAsks;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdict;
use Intakt\Core\Review\UseCase\PublishVerdict\PublishVerdictHandler;
use Intakt\Core\Review\UseCase\PublishVerdict\VerdictForTheLoop;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\MirrorQueuedRoundHandler;
use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

// The real queue-side handler and the real publication handler over recorded GitHub answers, because a build has no forge access, so this stands in for the live pull request the owner checks after merge.
#[Group('persistence')]
#[CoversClass(MirrorQueuedRoundHandler::class)]
#[CoversClass(PublishVerdictHandler::class)]
#[UsesClass(RoundStatusMirror::class)]
#[UsesClass(StoredLatestRoundStatus::class)]
final class StatusLabelsOverRecordedGitHubTest extends KernelTestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';

    private RoundFixture $fixture;

    private RecordedStatusLabels $pull;

    private LockFactory $locks;

    // Evidence E1 of #3932: the labels before a round, after it is queued and after the publication handler published its verdict.
    public function testAPullRequestsStatusLabelGoesFromNoneToInReviewWhenItsRoundIsQueuedAndToWaitingOnYouOnceItsVerdictIsPublished(): void
    {
        $before = $this->pull->labels;

        $roundId = $this->queuedRound();
        $afterQueueing = $this->pull->labels;
        $this->publish($roundId);

        self::assertSame([[], ['status:in-review'], ['status:waiting-on-you'], []], [$before, $afterQueueing, $this->pull->labels, $this->pull->comments]);
    }

    public function testAJudgedRoundWhosePublicationHasNotRunStaysInReviewAfterTheNextReconcile(): void
    {
        $this->queuedRound();
        $this->judge();

        $this->mirror()->reconcile('alpha', new ChangeId(self::PULL));

        self::assertSame(['status:in-review'], $this->pull->labels);
    }

    public function testAVerdictPublishedWhileTheQueueWriterHoldsTheLockEndsWaitingOnYouAfterTheNextReconcileWithoutAComment(): void
    {
        $roundId = $this->fixture->startFor(self::PULL, $this->fixture->bus, 2);
        $this->judge();
        $this->pull->whileReadingLabels = fn () => $this->publishWithoutJudging($roundId);

        $this->queueSide()($this->fixture->bus->announced[0]);
        $leftByTheQueueWriter = $this->pull->labels;
        $this->mirror()->reconcile('alpha', new ChangeId(self::PULL));

        self::assertSame([['status:in-review'], ['status:waiting-on-you'], []], [$leftByTheQueueWriter, $this->pull->labels, $this->pull->comments]);
        self::assertSame(['The status label of {change} of {project} was left to the writer holding its lock.'], array_column($this->fixture->log->cleanLogs(), 1));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']));
        $this->pull = new RecordedStatusLabels('/repos/acme/widgets/issues/7');
        $this->locks = new LockFactory(new InMemoryStore());
    }

    private function queuedRound(): string
    {
        $roundId = $this->fixture->startFor(self::PULL, $this->fixture->bus, 2);
        $this->queueSide()($this->fixture->bus->announced[0]);

        return $roundId;
    }

    private function publish(string $roundId): void
    {
        $this->judge();
        $this->publishWithoutJudging($roundId);
    }

    private function judge(): void
    {
        $vendor = new VerdictPerRole([]);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        foreach ($this->fixture->judgings() as $judging) {
            $this->fixture->judge($vendor)($judging);
        }
    }

    // The application's queue, because the judge transition of the application's workflow queues the publication.
    private function publishWithoutJudging(string $roundId): void
    {
        $queue = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        foreach ($queue->getSent() as $sent) {
            $message = $sent->getMessage();
            if ($message instanceof PublishVerdict && $roundId === $message->roundId) {
                $this->publicationHandler()($message);
            }
        }
    }

    private function queueSide(): MirrorQueuedRoundHandler
    {
        return new MirrorQueuedRoundHandler($this->fixture->transactions(), $this->mirror());
    }

    private function publicationHandler(): PublishVerdictHandler
    {
        $handed = new HandedOverChanges($this->fixture->ledger());
        $protected = self::createStub(ProtectedHeads::class);
        $protected->method('of')->willReturn(ProtectedChanges::touching(new Paths()));
        $verdicts = new VerdictForTheLoop(new StoredVerdicts(self::getContainer()->get('serializer.stored')), $this->fixture->findingStore(), new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])), new ChangeDecision());

        return new PublishVerdictHandler($this->fixture->transactions(), new ConfiguredProjectModes(['alpha' => 'reviewer-for-loop']), $handed, $verdicts, new OwnerAsks($handed, $protected, $this->fixture->ledger()), new ScriptedGitHub()->forge(), new ChangePublications(new LockFactory(new InMemoryStore())), $this->fixture->log, $this->mirror());
    }

    // One lock store for every writer, as the workers of one install share theirs.
    private function mirror(): RoundStatusMirror
    {
        return new RoundStatusMirror(new StoredLatestRoundStatus($this->fixture->transactions()), $this->locks, $this->fixture->log, $this->pull->adapter('acme/widgets'));
    }
}
