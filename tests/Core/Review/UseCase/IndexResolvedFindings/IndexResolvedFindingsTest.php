<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\IndexResolvedFindings;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\UseCase\IndexResolvedFindings\IndexResolvedFindings;
use Intakt\Core\Review\UseCase\IndexResolvedFindings\IndexResolvedFindingsHandler;
use Intakt\Core\Review\UseCase\IndexResolvedFindings\QueueIndexingOfCompletedRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RecordingBus;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use stdClass;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\Retriever;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Throwable;

#[Group('persistence')]
#[CoversClass(IndexResolvedFindingsHandler::class)]
#[CoversClass(IndexResolvedFindings::class)]
#[CoversClass(QueueIndexingOfCompletedRound::class)]
#[CoversClass(StoredFindings::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(FindingLedger::class)]
#[UsesClass(TrackedFinding::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(StoreMemoryIndex::class)]
#[UsesClass(StoreProjectMemory::class)]
#[UsesClass(MemoryDocuments::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(MemoryQuery::class)]
#[UsesClass(Paths::class)]
#[UsesClass(RelevantMemory::class)]
#[UsesClass(ProjectMemoryNeedsEmbeddingModel::class)]
final class IndexResolvedFindingsTest extends KernelTestCase
{
    private const string CHANGE = 'feat/thing';
    private const string FIRST = 'Look at part 1.';
    private const string SECOND = 'Look at part 2.';

    private RoundFixture $fixture;
    private StoreMemoryIndex $index;
    private StoreProjectMemory $memory;
    private ?RecordingBus $lastQueue = null;

    public function testAFindingAReviewerMarksResolvedIsIndexedWithItsCauseAndFileWhenTheRoundCompletes(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $second = $this->roundResolving(['F1']);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($second));

        $expected = new MemoryEntry('alpha', 'Resolved finding: Text a'.PHP_EOL.'Cause: The rule a.'.PHP_EOL.'Evidence: probe a: output a', new Paths('src/A.php'));
        self::assertEquals([$expected], $this->remembered(['src/A.php']));
    }

    public function testAResolvedFindingIsRememberedForTheProjectOfItsRoundOnly(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $second = $this->roundResolving(['F1']);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($second));

        self::assertSame([], $this->memory->relevantTo(new MemoryQuery('beta', new Paths('src/A.php'), 100000))->entries);
    }

    public function testAFindingStillOpenAfterTheRoundIsNotIndexed(): void
    {
        $this->roundReporting([$this->finding('a'), $this->finding('b')]);
        $second = $this->roundResolving(['F1']);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($second));

        self::assertCount(1, $this->remembered(['src/A.php']));
    }

    public function testARoundThatResolvesNothingIndexesNothing(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $second = $this->roundResolving([]);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($second));

        self::assertSame([], $this->remembered(['src/A.php']));
    }

    public function testAFailedReviewsResolvedIdsAreNotIndexed(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $failed = new Verdict(Decision::Changes, [new Finding(Verdict::INVALID_OUTPUT, Severity::P1, null, null, null, 'Broken.', FixableBy::Builder, false, null, null)], ['F1'], []);
        $second = $this->deliveredRound([self::FIRST => $failed]);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($second));

        self::assertSame([], $this->remembered(['src/A.php']));
    }

    public function testAFindingAnotherReviewerReportedAgainInTheSameRoundIsNotIndexed(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $second = $this->deliveredRound([
            self::FIRST => new Verdict(Decision::Changes, [], ['F1'], []),
            self::SECOND => new Verdict(Decision::Changes, [$this->finding('F1', true)], [], []),
        ]);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($second));

        self::assertSame([], $this->remembered(['src/A.php']));
    }

    public function testALaterRoundReopeningAFindingDoesNotEraseTheResolutionAnEarlierRoundIndexesLate(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $resolving = $this->roundResolving(['F1']);
        $this->roundReporting([$this->finding('F1', true)]);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($resolving));

        self::assertCount(1, $this->remembered(['src/A.php']));
    }

    public function testARoundOvertakenByANewerRecordedRoundDoesNotIndexAFindingTheNewerRoundKeepsOpen(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $queue = new RecordingBus();
        $older = $this->fixture->startFor(self::CHANGE, $queue, 2);
        $this->roundReporting([$this->finding('F1', true)]);
        $this->deliverAll($queue, [self::FIRST => new Verdict(Decision::Approve, [], ['F1'], [])]);

        $this->fixture->indexing($this->index)(new IndexResolvedFindings($older));

        self::assertTrue($this->fixture->storedFindings(self::CHANGE)[0]->isOpen(), 'the ledger keeps F1 open');
        self::assertSame([], $this->remembered(['src/A.php']));
    }

    public function testWithoutAnEmbeddingModelTheIndexingIsDoneWithOneInfoLineAndNoTrace(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $second = $this->roundResolving(['F1']);
        $this->fixture->log->cleanLogs();

        $this->fixture->indexing($this->indexFailingWith(new ProjectMemoryNeedsEmbeddingModel()))(new IndexResolvedFindings($second));

        self::assertSame([['info', new ProjectMemoryNeedsEmbeddingModel()->getMessage(), ['project' => 'alpha']]], $this->fixture->log->cleanLogs());
    }

    public function testAnyOtherIndexingFailureIsThrownSoTheMessageIsRetried(): void
    {
        $this->roundReporting([$this->finding('a')]);
        $second = $this->roundResolving(['F1']);

        $this->expectExceptionObject(new RuntimeException('The store is down.'));

        $this->fixture->indexing($this->indexFailingWith(new RuntimeException('The store is down.')))(new IndexResolvedFindings($second));
    }

    public function testACompletedRoundQueuesItsIndexingOnce(): void
    {
        $id = $this->roundReporting([$this->finding('a')]);

        $this->fixture->deliver(array_first($this->lastQueue->messages ?? []) ?? throw new LogicException('No round was started.'), new VerdictPerRole([]));

        self::assertEquals([new IndexResolvedFindings($id)], $this->fixture->indexings());
    }

    public function testARoundStillCollectingIsNotQueuedForIndexing(): void
    {
        $this->fixture->start(2);

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([], $this->fixture->indexings());
    }

    public function testTheQueueingListenerIgnoresASubjectThatIsNoRound(): void
    {
        $bus = new RecordingBus();
        $listener = new QueueIndexingOfCompletedRound($bus);
        /** @var CompletedEvent<object> $event */
        $event = new CompletedEvent(new stdClass(), new Marking(), new Transition('complete', 'a', 'b'));

        $listener($event);

        self::assertSame([], $bus->messages);
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $store = new Store();
        $platform = MockPlatformFactory::createPlatform(static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(
            array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input]),
        ));
        $vectorizer = new Vectorizer($platform, 'embedder');
        $documents = new MemoryDocuments();
        $this->index = new StoreMemoryIndex(new DocumentIndexer(new DocumentProcessor($vectorizer, $store)), $documents);
        $this->memory = new StoreProjectMemory(new Retriever($store, $vectorizer), $documents);
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function deliveredRound(array $verdicts): string
    {
        $queue = new RecordingBus();
        $id = $this->fixture->startFor(self::CHANGE, $queue, 2);
        $this->deliverAll($queue, $verdicts);
        $this->lastQueue = $queue;

        return $id;
    }

    /**
     * @param array<string, Verdict> $verdicts
     */
    private function deliverAll(RecordingBus $queue, array $verdicts): void
    {
        $vendor = new VerdictPerRole($verdicts);

        foreach ($queue->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    private function indexFailingWith(Throwable $failure): MemoryIndex
    {
        $index = self::createStub(MemoryIndex::class);
        $index->method('index')->willThrowException($failure);

        return $index;
    }

    private function finding(string $id, bool $repeat = false): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, 'Text '.$id, FixableBy::Builder, $repeat, 'The rule '.$id.'.', new Evidence('probe '.$id, 'output '.$id));
    }

    /**
     * @param list<Finding> $findings
     */
    private function roundReporting(array $findings): string
    {
        return $this->deliveredRound([self::FIRST => new Verdict(Decision::Changes, $findings, [], [])]);
    }

    /**
     * @param list<string> $resolved
     */
    private function roundResolving(array $resolved): string
    {
        return $this->deliveredRound([self::FIRST => new Verdict(Decision::Changes, [], $resolved, [])]);
    }

    /**
     * @param list<string> $files
     *
     * @return list<MemoryEntry>
     */
    private function remembered(array $files): array
    {
        return $this->memory->relevantTo(new MemoryQuery('alpha', new Paths(...$files), 100000))->entries;
    }
}
