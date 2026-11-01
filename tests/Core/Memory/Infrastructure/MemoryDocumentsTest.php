<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use Intakt\Core\Memory\Infrastructure\StoredMemoryIsMalformed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;

#[CoversClass(MemoryDocuments::class)]
#[CoversClass(StoredMemoryIsMalformed::class)]
#[UsesClass(MemoryEntry::class)]
final class MemoryDocumentsTest extends TestCase
{
    public function testFileListsThatJoinToTheSameTextGetDistinctIds(): void
    {
        $documents = new MemoryDocuments();

        $first = $documents->toDocument(new MemoryEntry('Same note.', ['a b.php']));
        $second = $documents->toDocument(new MemoryEntry('Same note.', ['a', 'b.php']));

        self::assertNotEquals($first->getId(), $second->getId());
    }

    public function testAWellFormedDocumentBecomesItsEntry(): void
    {
        $entry = new MemoryDocuments()->toEntry($this->document(['entry' => 'A note.', 'files' => ['a.php', 'b.php']]));

        self::assertEquals(new MemoryEntry('A note.', ['a.php', 'b.php']), $entry);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('malformedMetadata')]
    public function testAMalformedDocumentIsRefusedNamingItsIdAndTheOffendingKey(array $metadata, string $key): void
    {
        $this->expectException(StoredMemoryIsMalformed::class);
        $this->expectExceptionMessageMatches(sprintf('/"doc-1" has a missing or malformed "%s"/', $key));

        new MemoryDocuments()->toEntry($this->document($metadata));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedMetadata(): iterable
    {
        yield 'missing entry' => [['files' => []], 'entry'];
        yield 'entry that is not a string' => [['entry' => 5, 'files' => []], 'entry'];
        yield 'missing files' => [['entry' => 'A note.'], 'files'];
        yield 'files that are not a list' => [['entry' => 'A note.', 'files' => ['x' => 'a.php']], 'files'];
        yield 'files that are a string' => [['entry' => 'A note.', 'files' => 'a.php'], 'files'];
        yield 'files holding a non-string' => [['entry' => 'A note.', 'files' => ['a.php', 7]], 'files'];
    }

    public function testTheSameEntryGetsTheSameId(): void
    {
        $documents = new MemoryDocuments();

        $first = $documents->toDocument(new MemoryEntry('Same note.', ['a.php']));
        $second = $documents->toDocument(new MemoryEntry('Same note.', ['a.php']));

        self::assertEquals($first->getId(), $second->getId());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function document(array $metadata): VectorDocument
    {
        return new VectorDocument('doc-1', new Vector([1.0, 0.0]), new Metadata($metadata));
    }
}
