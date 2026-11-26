<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\Retriever;

#[CoversClass(StoreProjectMemory::class)]
#[CoversClass(StoreMemoryIndex::class)]
#[CoversClass(MemoryDocuments::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(MemoryQuery::class)]
#[UsesClass(RelevantMemory::class)]
#[UsesClass(SettledFinding::class)]
#[UsesClass(SettledMemory::class)]
#[UsesClass(SettledQuery::class)]
#[UsesClass(CodeSnapshot::class)]
#[UsesClass(Paths::class)]
final class StoreProjectMemoryTest extends TestCase
{
    private StoreMemoryIndex $index;
    private StoreProjectMemory $memory;

    private bool $embedderDown = false;

    public function testAnIndexedEntryIsRetrievedForAFileItDescribes(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Run the migration first.', new Paths('src/Db.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('src/Db.php', 'src/Other.php'), 1000));

        self::assertEquals([new MemoryEntry('alpha', 'Run the migration first.', new Paths('src/Db.php'))], $found->entries);
    }

    public function testAnEntryAboutOtherFilesIsNotReturned(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'About the router.', new Paths('src/Router.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('src/Db.php'), 1000));

        self::assertSame([], $found->entries);
    }

    public function testAnEntryOfAnotherProjectIsNotReturnedForTheSameFile(): void
    {
        $this->index->index(new MemoryEntry('beta', 'Beta keeps its billing here.', new Paths('composer.json')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('composer.json'), 1000));

        self::assertSame([], $found->entries);
    }

    public function testTheSameNoteInTwoProjectsIsKeptForEach(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Same note.', new Paths('a.php')));
        $this->index->index(new MemoryEntry('beta', 'Same note.', new Paths('a.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('beta', new Paths('a.php'), 1000));

        self::assertEquals([new MemoryEntry('beta', 'Same note.', new Paths('a.php'))], $found->entries);
    }

    public function testRetrievalStopsBeforeTheEntryThatWouldExceedTheCap(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'aaaa', new Paths('a.php')));
        $this->index->index(new MemoryEntry('alpha', 'bbbb', new Paths('a.php')));
        $this->index->index(new MemoryEntry('alpha', 'cccc', new Paths('a.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 9));

        self::assertCount(2, $found->entries);
    }

    public function testALoneEntryLargerThanTheWholeCapIsStillReturnedForTheCallerToShorten(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'far too long for the cap', new Paths('a.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 5));

        self::assertSame(['far too long for the cap'], array_map(static fn (MemoryEntry $entry): string => $entry->text, $found->entries));
    }

    public function testRetrievalFromAnEmptyMemoryReturnsNoEntries(): void
    {
        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 1000));

        self::assertSame([], $found->entries);
    }

    public function testNoFilesUnderReviewReturnNoEntries(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Anything.', new Paths('a.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths(), 1000));

        self::assertSame([], $found->entries);
    }

    public function testIndexingTheSameEntryTwiceKeepsOneCopy(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Once.', new Paths('a.php')));
        $this->index->index(new MemoryEntry('alpha', 'Once.', new Paths('a.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 1000));

        self::assertCount(1, $found->entries);
    }

    public function testASettledFindingIsStoredWithItsReasonAndCodeHash(): void
    {
        $finding = $this->settled('alpha', 'hash-1');
        $this->index->settle($finding);

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')));

        self::assertEquals([$finding], $found->settled);
        self::assertSame('Intentional: the lock is taken by the caller.', $found->settled[0]->reason);
        self::assertSame('hash-1', $found->settled[0]->code->hash);
    }

    public function testASettledFindingIsNoLongerReturnedAsSettledAfterMainChangesItsCode(): void
    {
        $finding = $this->settled('alpha', 'hash-1');
        $this->index->settle($finding);

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-2')));

        self::assertSame([], $found->settled);
        self::assertEquals([$finding], $found->stale);
    }

    public function testASettledFindingAboutOtherCodeIsNeitherSettledNorStale(): void
    {
        $this->index->settle($this->settled('alpha', 'hash-1'));

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'other', 'hash-1')));

        self::assertEquals(SettledMemory::none(), $found);
    }

    public function testASettledFindingSettledAgainWithTheNewHashIsSettledAgain(): void
    {
        $this->index->settle($this->settled('alpha', 'hash-1'));
        $this->index->settle($this->settled('alpha', 'hash-2'));

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-2')));

        self::assertCount(1, $found->settled);
        self::assertSame([], $found->stale);
    }

    public function testADeletedSettledFindingIsNotReturned(): void
    {
        $finding = $this->settled('alpha', 'hash-1');
        $this->index->settle($finding);
        $this->index->forget($finding);

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')));

        self::assertEquals(SettledMemory::none(), $found);
    }

    public function testASettledFindingOfAnotherProjectIsNeverReturned(): void
    {
        $this->index->settle($this->settled('beta', 'hash-1'));

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')));

        self::assertEquals(SettledMemory::none(), $found);
    }

    public function testASettledFindingIsNotReturnedAsResolvedMemory(): void
    {
        $this->index->settle($this->settled('alpha', 'hash-1'));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('src/Db.php'), 1000));

        self::assertSame([], $found->entries);
    }

    public function testAResolvedEntryIsNotReturnedAsSettled(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Run the migration first.', new Paths('src/Db.php')));

        $found = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')));

        self::assertEquals(SettledMemory::none(), $found);
    }

    public function testNoCodeUnderReviewReturnsNoSettledFindings(): void
    {
        $this->index->settle($this->settled('alpha', 'hash-1'));

        self::assertEquals(SettledMemory::none(), $this->memory->settledOn(new SettledQuery('alpha')));
    }

    public function testReSettlingWhileTheEmbedderFailsPropagatesTheFailureAndKeepsTheOriginalEntry(): void
    {
        $original = $this->settled('alpha', 'hash-1');
        $this->index->settle($original);

        $failure = $this->reSettleWhileTheEmbedderFails($this->settled('alpha', 'hash-2'));

        self::assertSame('embedder down', $failure);
        self::assertEquals([$original], $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')))->settled);
        self::assertEquals([$original], $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-2')))->stale);
    }

    protected function setUp(): void
    {
        $store = new Store();
        // Every text embeds to the same vector, so only the plain-code file match and the cap decide the result.
        $platform = MockPlatformFactory::createPlatform(fn (Model $model, array|string|object $input): VectorResult => $this->embedderDown
            ? throw new RuntimeException('embedder down') : new VectorResult(array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input])));
        $vectorizer = new Vectorizer($platform, 'embedder');
        $documents = new MemoryDocuments();

        $this->index = new StoreMemoryIndex(new DocumentIndexer(new DocumentProcessor($vectorizer, $store)), $vectorizer, $store, $documents);
        $this->memory = new StoreProjectMemory(new Retriever($store, $vectorizer), $documents);
    }

    private function settled(string $project, string $hash): SettledFinding
    {
        return new SettledFinding($project, 'migrate() takes no lock.', 'Intentional: the lock is taken by the caller.', new CodeSnapshot('src/Db.php', 'migrate', $hash));
    }

    private function reSettleWhileTheEmbedderFails(SettledFinding $changed): string
    {
        $this->embedderDown = true;

        try {
            $this->index->settle($changed);
        } catch (RuntimeException $failure) {
            return $failure->getMessage();
        } finally {
            $this->embedderDown = false;
        }

        self::fail('Settling while the embedder is down must fail.');
    }
}
