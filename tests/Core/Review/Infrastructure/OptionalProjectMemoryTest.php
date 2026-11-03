<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use Intakt\Core\Review\Infrastructure\OptionalProjectMemory;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(OptionalProjectMemory::class)]
#[UsesClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(MemoryQuery::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(RelevantMemory::class)]
final class OptionalProjectMemoryTest extends TestCase
{
    public function testAReadableMemoryIsPassedThrough(): void
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willReturn(RelevantMemory::within([new MemoryEntry('alpha', 'Note.', ['a.php'])], 100));

        $found = new OptionalProjectMemory($inner, self::createStub(LoggerInterface::class))->relevantTo(new MemoryQuery('alpha', ['a.php'], 100));

        self::assertCount(1, $found->entries);
    }

    public function testAMemoryThatCannotBeReadGivesNoEntries(): void
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willThrowException(new LogicException('Retrieval failed.'));

        $found = new OptionalProjectMemory($inner, self::createStub(LoggerInterface::class))->relevantTo(new MemoryQuery('alpha', ['a.php'], 100));

        self::assertSame([], $found->entries);
    }

    // The reviewer's probe: the failure was once logged by its class alone.
    public function testAMemoryThatCannotBeReadIsLoggedWithTheProjectAndTheFailuresTrace(): void
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willThrowException(new LogicException('Retrieval failed.'));
        $handler = new TestHandler();

        new OptionalProjectMemory($inner, new Logger('app', [$handler]))->relevantTo(new MemoryQuery('alpha', ['a.php'], 100));

        /** @var array{context: array{project: string, exception: array{class: string, message: string, trace: list<string>}}} $line */
        $line = json_decode(new RedactedJsonFormatter([], [])->format($handler->getRecords()[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['alpha', LogicException::class, 'Retrieval failed.'], [$line['context']['project'], $line['context']['exception']['class'], $line['context']['exception']['message']]);
        self::assertNotEmpty($line['context']['exception']['trace']);
    }
}
