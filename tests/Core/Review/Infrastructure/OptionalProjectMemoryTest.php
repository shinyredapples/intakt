<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Platform\Contract\Redaction;
use Intakt\Core\Platform\Infrastructure\HeldCredentials;
use Intakt\Core\Platform\Infrastructure\RedactedJsonFormatter;
use Intakt\Core\Review\Infrastructure\OptionalProjectMemory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Log\Logger;

#[CoversClass(OptionalProjectMemory::class)]
#[UsesClass(MemoryQuery::class)]
#[UsesClass(MemoryEntry::class)]
#[UsesClass(RelevantMemory::class)]
#[UsesClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(HeldCredentials::class)]
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
        $found = new OptionalProjectMemory($this->failing(), self::createStub(LoggerInterface::class))->relevantTo(new MemoryQuery('alpha', ['a.php'], 100));

        self::assertSame([], $found->entries);
    }

    public function testAMemoryThatCannotBeReadIsLoggedWithItsProjectAndTheFailuresMessageAndTrace(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $logger = new Logger('warning', $output, new RedactedJsonFormatter(new Redaction(), new MockClock(), new HeldCredentials([])));

        $_ = new OptionalProjectMemory($this->failing(), $logger)->relevantTo(new MemoryQuery('alpha', ['a.php'], 100));

        rewind($output);
        $record = json_decode((string) stream_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame(['alpha', LogicException::class, 'the store is not set up'], [
            $record['context']['project'] ?? null,
            $record['context']['exception']['class'] ?? null,
            $record['context']['exception']['message'] ?? null,
        ]);
        self::assertStringContainsString(__CLASS__, $record['context']['exception']['trace'] ?? '');
    }

    private function failing(): ProjectMemory
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willThrowException(new LogicException('the store is not set up'));

        return $inner;
    }
}
