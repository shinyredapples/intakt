<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Sqlite\Store;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\Retriever;

#[Group('persistence')]
#[CoversNothing]
final class SqliteMemoryStoreTest extends TestCase
{
    private StoreMemoryIndex $index;
    private StoreProjectMemory $memory;

    protected function setUp(): void
    {
        $store = Store::fromPdo(new PDO('sqlite::memory:'), 'project_memory');
        $store->setup();
        $platform = MockPlatformFactory::createPlatform(static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(
            array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input]),
        ));
        $vectorizer = new Vectorizer($platform, 'embedder');
        $documents = new MemoryDocuments();

        $this->index = new StoreMemoryIndex(new DocumentIndexer(new DocumentProcessor($vectorizer, $store)), $store, $documents);
        $this->memory = new StoreProjectMemory(new Retriever($store, $vectorizer), $documents);
    }

    public function testAnEntryIndexedInTheSqliteStoreIsRetrievedForItsFile(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Run the migration first.', new Paths('src/Db.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('src/Db.php'), 1000));

        self::assertEquals([new MemoryEntry('alpha', 'Run the migration first.', new Paths('src/Db.php'))], $found->entries);
    }

    public function testAnEntryForAPathWithAQuoteIsRetrievedFromTheSqliteStore(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Note.', new Paths('src/Quote"d.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('src/Quote"d.php'), 1000));

        self::assertEquals([new MemoryEntry('alpha', 'Note.', new Paths('src/Quote"d.php'))], $found->entries);
    }

    public function testEntriesForFileListsThatJoinToTheSameTextAreBothKept(): void
    {
        $this->index->index(new MemoryEntry('alpha', 'Same note.', new Paths('a b.php')));
        $this->index->index(new MemoryEntry('alpha', 'Same note.', new Paths('a', 'b.php')));

        $found = $this->memory->relevantTo(new MemoryQuery('alpha', new Paths('a b.php'), 1000));

        self::assertEquals([new MemoryEntry('alpha', 'Same note.', new Paths('a b.php'))], $found->entries);
    }

    public function testASettledFindingStoredOnSqliteTurnsStaleWhenItsHashChangesAndIsGoneOnceDeleted(): void
    {
        $finding = new SettledFinding('alpha', 'migrate() takes no lock.', 'Intentional.', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1'));
        $this->index->settle($finding);

        $unchanged = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')));
        $changed = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-2')));
        $this->index->forget($finding);
        $deleted = $this->memory->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', 'hash-1')));

        self::assertSame([1, 0, 0, 1, 0, 0], [count($unchanged->settled), count($unchanged->stale), count($changed->settled), count($changed->stale), count($deleted->settled), count($deleted->stale)]);
    }
}
