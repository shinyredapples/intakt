<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Review\Infrastructure\OptionalProjectMemory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(OptionalProjectMemory::class)]
#[UsesClass(MemoryQuery::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(RelevantMemory::class)]
final class OptionalProjectMemoryTest extends TestCase
{
    public function testAReadableMemoryIsPassedThrough(): void
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willReturn(RelevantMemory::within([new MemoryEntry('Note.', ['a.php'])], 100));

        $found = new OptionalProjectMemory($inner, self::createStub(LoggerInterface::class))->relevantTo(new MemoryQuery(['a.php'], 100));

        self::assertCount(1, $found->entries);
    }

    public function testAMemoryThatCannotBeReadGivesNoEntriesAndIsLoggedByClass(): void
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willThrowException(new LogicException('needs a key sk-secret'));
        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::anything(), ['failure' => LogicException::class]);

        $found = new OptionalProjectMemory($inner, $logger)->relevantTo(new MemoryQuery(['a.php'], 100));

        self::assertSame([], $found->entries);
    }
}
