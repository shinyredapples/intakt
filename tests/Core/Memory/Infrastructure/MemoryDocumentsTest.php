<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Infrastructure\MemoryDocuments;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoryDocuments::class)]
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

    public function testTheSameEntryGetsTheSameId(): void
    {
        $documents = new MemoryDocuments();

        $first = $documents->toDocument(new MemoryEntry('Same note.', ['a.php']));
        $second = $documents->toDocument(new MemoryEntry('Same note.', ['a.php']));

        self::assertEquals($first->getId(), $second->getId());
    }
}
