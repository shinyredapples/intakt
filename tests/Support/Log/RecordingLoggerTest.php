<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use Intakt\Tests\Support\Log\Fixtures\StringableName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RecordingLogger::class)]
#[UsesClass(LogRecords::class)]
#[UsesClass(StringableName::class)]
final class RecordingLoggerTest extends TestCase
{
    private LogRecords $records;

    public function testATemplatedMessageIsRecordedWithItsPlaceholdersFilledIn(): void
    {
        new RecordingLogger($this->records)->error('Failed {name} after {seconds}s.', ['name' => 'the sync', 'seconds' => 3]);

        self::assertSame([['level' => 'error', 'message' => 'Failed the sync after 3s.']], $this->records->takeErrors());
    }

    public function testAStringableContextValueFillsItsPlaceholder(): void
    {
        $name = new StringableName();

        new RecordingLogger($this->records)->error('Failed {name}.', ['name' => $name]);

        self::assertSame([['level' => 'error', 'message' => 'Failed stringable name.']], $this->records->takeErrors());
    }

    public function testAPlaceholderWhoseContextValueIsNotScalarOrStringableStaysAsItIs(): void
    {
        new RecordingLogger($this->records)->error('Failed {items} and {missing}.', ['items' => ['a']]);

        self::assertSame([['level' => 'error', 'message' => 'Failed {items} and {missing}.']], $this->records->takeErrors());
    }

    public function testARecordWhoseContextHoldsAnExceptionIsMarkedAsTraced(): void
    {
        new RecordingLogger($this->records)->info('Retried {name}.', ['name' => 'the sync', 'exception' => new RuntimeException('Disk full.')]);

        self::assertSame([['level' => 'info', 'message' => 'Retried the sync.', 'traced' => true]], $this->records->all());
    }

    public function testARecordWithoutAnExceptionInItsContextIsNotMarkedAsTraced(): void
    {
        new RecordingLogger($this->records)->info('Retried {name}.', ['name' => 'the sync']);

        self::assertSame([['level' => 'info', 'message' => 'Retried the sync.']], $this->records->all());
    }

    public function testReadingEveryRecordLeavesThemInTheFile(): void
    {
        new RecordingLogger($this->records)->error('Disk on fire.');
        $this->records->all();

        self::assertSame([['level' => 'error', 'message' => 'Disk on fire.']], $this->records->takeErrors());
    }

    protected function setUp(): void
    {
        $this->records = new LogRecords((string) tempnam(sys_get_temp_dir(), 'intakt-recording-logger-test-'));
    }

    protected function tearDown(): void
    {
        $this->records->discard();
    }
}
