<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use Intakt\Core\Review\Infrastructure\OptionalProjectMemory;
use Intakt\Tests\Support\KernelTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;

#[Group('persistence')]
#[CoversClass(StoreMemoryIndex::class)]
#[CoversClass(StoreProjectMemory::class)]
#[UsesClass(OptionalProjectMemory::class)]
#[UsesClass(MemoryDocuments::class)]
#[UsesClass(SettledFinding::class)]
#[UsesClass(SettledQuery::class)]
#[UsesClass(SettledMemory::class)]
#[UsesClass(CodeSnapshot::class)]
#[UsesClass(Paths::class)]
final class SettledMemoryWiringTest extends KernelTestCase
{
    private string $memoryFile;

    public function testASettledFindingStoredThroughTheContainerIsSettledWhileItsCodeIsUnchanged(): void
    {
        $finding = $this->aSettledFinding('hash-1');

        $this->index()->settle($finding);

        self::assertEquals([[$finding], []], $this->settledAndStaleAt('hash-1'));
    }

    public function testASettledFindingStoredThroughTheContainerIsReportedStaleOnceTheCodeUnderItChanges(): void
    {
        $finding = $this->aSettledFinding('hash-1');
        $this->index()->settle($finding);

        $memory = $this->settledAndStaleAt('hash-2');

        self::assertEquals([[], [$finding]], $memory);
    }

    public function testASettledFindingForgottenThroughTheContainerIsNoLongerReturnedSettledOrStale(): void
    {
        $finding = $this->aSettledFinding('hash-1');
        $this->index()->settle($finding);
        self::assertEquals([[$finding], []], $this->settledAndStaleAt('hash-1'));

        $this->index()->forget($finding);

        self::assertEquals([[], []], $this->settledAndStaleAt('hash-1'));
        self::assertEquals([[], []], $this->settledAndStaleAt('hash-2'));
    }

    public function testTheContainersMemoryIndexWritesTheSettledFindingIntoTheConfiguredSqliteFile(): void
    {
        $this->index()->settle($this->aSettledFinding('hash-1'));

        $rows = new PDO('sqlite:'.$this->memoryFile)->query('SELECT COUNT(*) FROM project_memory');

        self::assertNotFalse($rows);
        self::assertSame(1, (int) $rows->fetchColumn());
    }

    protected function setUp(): void
    {
        $this->memoryFile = self::bootKernel()->getCacheDir().'/memory.db';
        // The file outlives the kernel in the worker's cache directory, so rows of an earlier test would leak into this one.
        if (is_file($this->memoryFile)) {
            unlink($this->memoryFile);
        }

        // Test environment only: the installed account would be OpenAI's, which no test may reach.
        self::getContainer()->set(EmbeddingsNotConfigured::class, MockPlatformFactory::createPlatform(
            static fn (Model $model, array|string|object $input): VectorResult => new VectorResult(array_map(static fn (): Vector => new Vector([1.0, 0.0]), is_array($input) ? $input : [$input])),
        ));
        self::getContainer()->get('ai.store.sqlite.memory')->setup();
    }

    private function aSettledFinding(string $hash): SettledFinding
    {
        return new SettledFinding('alpha', 'migrate() takes no lock.', 'Intentional: one writer.', new CodeSnapshot('src/Db.php', 'migrate', $hash));
    }

    private function index(): MemoryIndex
    {
        return self::getContainer()->get(MemoryIndex::class);
    }

    /**
     * @return array{list<SettledFinding>, list<SettledFinding>}
     */
    private function settledAndStaleAt(string $hash): array
    {
        $memory = self::getContainer()->get(ProjectMemory::class)->settledOn(new SettledQuery('alpha', new CodeSnapshot('src/Db.php', 'migrate', $hash)));

        return [$memory->settled, $memory->stale];
    }
}
