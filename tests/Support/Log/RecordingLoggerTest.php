<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Stringable;

#[CoversClass(RecordingLogger::class)]
#[UsesClass(LogRecords::class)]
final class RecordingLoggerTest extends TestCase
{
    private LogRecords $records;

    protected function setUp(): void
    {
        $this->records = LogRecords::startRun();
    }

    protected function tearDown(): void
    {
        $this->records->discard();
    }

    public function testATemplatedMessageIsRecordedWithItsPlaceholdersFilledIn(): void
    {
        (new RecordingLogger($this->records))->error('Failed {name} after {seconds}s.', ['name' => 'the sync', 'seconds' => 3]);

        self::assertSame([['level' => 'error', 'message' => 'Failed the sync after 3s.']], $this->records->takeErrors());
    }

    public function testAStringableContextValueFillsItsPlaceholder(): void
    {
        $name = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable name';
            }
        };

        (new RecordingLogger($this->records))->error('Failed {name}.', ['name' => $name]);

        self::assertSame([['level' => 'error', 'message' => 'Failed stringable name.']], $this->records->takeErrors());
    }

    public function testAPlaceholderWhoseContextValueIsNotScalarOrStringableStaysAsItIs(): void
    {
        (new RecordingLogger($this->records))->error('Failed {items} and {missing}.', ['items' => ['a']]);

        self::assertSame([['level' => 'error', 'message' => 'Failed {items} and {missing}.']], $this->records->takeErrors());
    }
}
