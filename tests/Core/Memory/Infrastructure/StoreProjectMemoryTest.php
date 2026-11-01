<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
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
final class StoreProjectMemoryTest extends TestCase
{
    private StoreMemoryIndex $index;
    private StoreProjectMemory $memory;

    protected function setUp(): void
    {
        $store = new Store();
        // Every text embeds to the same vector, so only the plain-code file match and the cap decide the result.
        $platform = MockPlatformFactory::createPlatform(static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(
            array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input]),
        ));
        $vectorizer = new Vectorizer($platform, 'embedder');
        $documents = new MemoryDocuments();

        $this->index = new StoreMemoryIndex(new DocumentIndexer(new DocumentProcessor($vectorizer, $store)), $documents);
        $this->memory = new StoreProjectMemory(new Retriever($store, $vectorizer), $documents);
    }

    public function testAnIndexedEntryIsRetrievedForAFileItDescribes(): void
    {
        $this->index->index(new MemoryEntry('Run the migration first.', ['src/Db.php']));

        $found = $this->memory->relevantTo(new MemoryQuery(['src/Db.php', 'src/Other.php'], 1000));

        self::assertEquals([new MemoryEntry('Run the migration first.', ['src/Db.php'])], $found->entries);
    }

    public function testAnEntryAboutOtherFilesIsNotReturned(): void
    {
        $this->index->index(new MemoryEntry('About the router.', ['src/Router.php']));

        $found = $this->memory->relevantTo(new MemoryQuery(['src/Db.php'], 1000));

        self::assertSame([], $found->entries);
    }

    public function testRetrievalStopsBeforeTheEntryThatWouldExceedTheCap(): void
    {
        $this->index->index(new MemoryEntry('aaaa', ['a.php']));
        $this->index->index(new MemoryEntry('bbbb', ['a.php']));
        $this->index->index(new MemoryEntry('cccc', ['a.php']));

        $found = $this->memory->relevantTo(new MemoryQuery(['a.php'], 9));

        self::assertCount(2, $found->entries);
    }

    public function testAnEntryLargerThanTheWholeCapIsNeverReturned(): void
    {
        $this->index->index(new MemoryEntry('far too long for the cap', ['a.php']));

        $found = $this->memory->relevantTo(new MemoryQuery(['a.php'], 5));

        self::assertSame([], $found->entries);
    }

    public function testRetrievalFromAnEmptyMemoryReturnsNoEntries(): void
    {
        $found = $this->memory->relevantTo(new MemoryQuery(['a.php'], 1000));

        self::assertSame([], $found->entries);
    }

    public function testNoFilesUnderReviewReturnNoEntries(): void
    {
        $this->index->index(new MemoryEntry('Anything.', ['a.php']));

        $found = $this->memory->relevantTo(new MemoryQuery([], 1000));

        self::assertSame([], $found->entries);
    }

    public function testIndexingTheSameEntryTwiceKeepsOneCopy(): void
    {
        $this->index->index(new MemoryEntry('Once.', ['a.php']));
        $this->index->index(new MemoryEntry('Once.', ['a.php']));

        $found = $this->memory->relevantTo(new MemoryQuery(['a.php'], 1000));

        self::assertCount(1, $found->entries);
    }
}
