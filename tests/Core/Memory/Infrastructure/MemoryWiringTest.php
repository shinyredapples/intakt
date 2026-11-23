<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Infrastructure\EmbeddingsNotConfigured;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoreMemoryIndex;
use Intakt\Core\Memory\Infrastructure\StoreProjectMemory;
use Intakt\Core\Review\Infrastructure\OptionalProjectMemory;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(EmbeddingsNotConfigured::class)]
#[UsesClass(StoreMemoryIndex::class)]
#[UsesClass(StoreProjectMemory::class)]
#[UsesClass(OptionalProjectMemory::class)]
#[UsesClass(MemoryDocuments::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(Paths::class)]
final class MemoryWiringTest extends KernelTestCase
{
    public function testReviewersAreWiredWithTheReadSideOfMemory(): void
    {
        self::assertInstanceOf(OptionalProjectMemory::class, self::getContainer()->get(ProjectMemory::class));
    }

    public function testWithoutAnOpenAiAccountIndexingFailsNamingTheMissingEmbeddingModel(): void
    {
        $index = self::getContainer()->get(MemoryIndex::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/needs an embedding model/');

        $index->index(new MemoryEntry('alpha', 'Text.', new Paths('src/A.php')));
    }
}
