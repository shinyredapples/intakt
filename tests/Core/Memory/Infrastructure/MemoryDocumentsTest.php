<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
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
#[UsesClass(Paths::class)]
final class MemoryDocumentsTest extends TestCase
{
    public function testFileListsThatJoinToTheSameTextGetDistinctIds(): void
    {
        $documents = new MemoryDocuments();

        $first = $documents->toDocument(new MemoryEntry('alpha', 'Same note.', new Paths('a b.php')));
        $second = $documents->toDocument(new MemoryEntry('alpha', 'Same note.', new Paths('a', 'b.php')));

        self::assertNotEquals($first->getId(), $second->getId());
    }

    public function testTheSameNoteInTwoProjectsGetsDistinctIds(): void
    {
        $documents = new MemoryDocuments();

        $first = $documents->toDocument(new MemoryEntry('alpha', 'Same note.', new Paths('a.php')));
        $second = $documents->toDocument(new MemoryEntry('beta', 'Same note.', new Paths('a.php')));

        self::assertNotEquals($first->getId(), $second->getId());
    }

    public function testAWellFormedDocumentBecomesItsEntry(): void
    {
        $entry = new MemoryDocuments()->toEntry($this->document(['project' => 'alpha', 'entry' => 'A note.', 'files' => ['a.php', 'b.php']]));

        self::assertEquals(new MemoryEntry('alpha', 'A note.', new Paths('a.php', 'b.php')), $entry);
    }

    public function testTheFilesAreStoredAsAPlainList(): void
    {
        $document = new MemoryDocuments()->toDocument(new MemoryEntry('alpha', 'A note.', new Paths('a.php', 'b.php')));

        self::assertSame(['a.php', 'b.php'], $document->getMetadata()['files']);
    }

    public function testTheIdIsTheHashOfTheProjectTheFileListAndTheText(): void
    {
        $document = new MemoryDocuments()->toDocument(new MemoryEntry('alpha', 'A note.', new Paths('a.php', 'b.php')));

        self::assertSame(hash('sha256', '["alpha",["a.php","b.php"],"A note."]'), $document->getId());
    }

    public function testTheProjectFilterKeepsADocumentOfThatProject(): void
    {
        $ofAlpha = new MemoryDocuments()->ofProject('alpha');

        self::assertTrue($ofAlpha($this->document(['project' => 'alpha', 'entry' => 'A note.', 'files' => []])));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('documentsOfNoOtherProject')]
    public function testTheProjectFilterDropsADocumentOfAnyOtherOrNoProject(array $metadata): void
    {
        $ofAlpha = new MemoryDocuments()->ofProject('alpha');

        self::assertFalse($ofAlpha($this->document($metadata)));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function documentsOfNoOtherProject(): iterable
    {
        yield 'another project' => [['project' => 'beta', 'entry' => 'A note.', 'files' => []]];
        yield 'no project' => [['entry' => 'A note.', 'files' => []]];
        yield 'a project that is not a string' => [['project' => ['alpha'], 'entry' => 'A note.', 'files' => []]];
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
        yield 'missing project' => [['entry' => 'A note.', 'files' => []], 'project'];
        yield 'project that is not a string' => [['project' => 5, 'entry' => 'A note.', 'files' => []], 'project'];
        yield 'missing entry' => [['project' => 'alpha', 'files' => []], 'entry'];
        yield 'entry that is not a string' => [['project' => 'alpha', 'entry' => 5, 'files' => []], 'entry'];
        yield 'missing files' => [['project' => 'alpha', 'entry' => 'A note.'], 'files'];
        yield 'files that are not a list' => [['project' => 'alpha', 'entry' => 'A note.', 'files' => ['x' => 'a.php']], 'files'];
        yield 'files that are a string' => [['project' => 'alpha', 'entry' => 'A note.', 'files' => 'a.php'], 'files'];
        yield 'files holding a non-string' => [['project' => 'alpha', 'entry' => 'A note.', 'files' => ['a.php', 7]], 'files'];
    }

    public function testTheSameEntryGetsTheSameId(): void
    {
        $documents = new MemoryDocuments();

        $first = $documents->toDocument(new MemoryEntry('alpha', 'Same note.', new Paths('a.php')));
        $second = $documents->toDocument(new MemoryEntry('alpha', 'Same note.', new Paths('a.php')));

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
