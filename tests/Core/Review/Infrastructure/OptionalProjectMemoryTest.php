<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\ProjectMemoryNeedsEmbeddingModel;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
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
#[UsesClass(Paths::class)]
#[UsesClass(RelevantMemory::class)]
#[UsesClass(SettledMemory::class)]
#[UsesClass(SettledQuery::class)]
#[UsesClass(CodeSnapshot::class)]
#[UsesClass(ProjectMemoryNeedsEmbeddingModel::class)]
#[UsesClass(RedactedJsonFormatter::class)]
#[UsesClass(Redaction::class)]
#[UsesClass(HeldCredentials::class)]
final class OptionalProjectMemoryTest extends TestCase
{
    public function testAReadableMemoryIsPassedThrough(): void
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willReturn(RelevantMemory::within([new MemoryEntry('alpha', 'Note.', new Paths('a.php'))], 100));

        $found = new OptionalProjectMemory($inner, self::createStub(LoggerInterface::class))->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 100));

        self::assertCount(1, $found->entries);
    }

    public function testSettledFindingsOfAMemoryThatCannotBeReadAreNone(): void
    {
        $found = new OptionalProjectMemory($this->failing(), self::createStub(LoggerInterface::class))->settledOn(new SettledQuery('alpha', new CodeSnapshot('a.php', '', 'h')));

        self::assertEquals(SettledMemory::none(), $found);
    }

    public function testAMemoryThatCannotBeReadGivesNoEntries(): void
    {
        $found = new OptionalProjectMemory($this->failing(), self::createStub(LoggerInterface::class))->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 100));

        self::assertSame([], $found->entries);
    }

    public function testAMemoryThatCannotBeReadIsLoggedWithItsProjectAndTheFailuresMessageAndTrace(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $logger = new Logger('warning', $output, new RedactedJsonFormatter(new Redaction(), new MockClock(), new HeldCredentials([])));

        new OptionalProjectMemory($this->failing(), $logger)->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 100));

        rewind($output);
        /** @var array{context: array{project: string, exception: array{class: string, message: string, trace: string}}} $record */
        $record = json_decode((string) stream_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $failure = $record['context']['exception'];
        self::assertSame(['alpha', LogicException::class, 'the store is not set up'], [$record['context']['project'], $failure['class'], $failure['message']]);
        self::assertStringContainsString(self::class, $failure['trace']);
    }

    public function testAMissingEmbeddingModelIsLoggedOnceAtInfoLevelWithoutAStackTraceHoweverManyCallsAreMade(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $logger = new Logger('debug', $output, new RedactedJsonFormatter(new Redaction(), new MockClock(), new HeldCredentials([])));
        $memory = new OptionalProjectMemory($this->failing(new ProjectMemoryNeedsEmbeddingModel()), $logger);

        $found = [];
        foreach (range(1, 3) as $ignored) {
            $found[] = $memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 100))->entries;
        }

        rewind($output);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($output)), static fn (string $line): bool => '' !== $line));
        self::assertCount(1, $lines);
        /** @var array{level: string, context: array<string, mixed>} $record */
        $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['info', ['project' => 'alpha']], [$record['level'], $record['context']]);
        self::assertStringNotContainsString('trace', $lines[0]);
        self::assertSame([[], [], []], $found);
    }

    public function testEveryOtherMemoryFailureIsStillLoggedOnEachCall(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $logger = new Logger('debug', $output, new RedactedJsonFormatter(new Redaction(), new MockClock(), new HeldCredentials([])));
        $memory = new OptionalProjectMemory($this->failing(), $logger);

        $memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 100));
        $memory->relevantTo(new MemoryQuery('alpha', new Paths('a.php'), 100));

        rewind($output);
        self::assertCount(2, array_filter(explode("\n", (string) stream_get_contents($output)), static fn (string $line): bool => '' !== $line));
    }

    private function failing(?LogicException $failure = null): ProjectMemory
    {
        $inner = self::createStub(ProjectMemory::class);
        $inner->method('relevantTo')->willThrowException($failure ?? new LogicException('the store is not set up'));
        $inner->method('settledOn')->willThrowException($failure ?? new LogicException('the store is not set up'));

        return $inner;
    }
}
