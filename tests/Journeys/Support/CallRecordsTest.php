<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CallRecords::class)]
#[UsesClass(RecordedProcess::class)]
final class CallRecordsTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-calls-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/calls', 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTwoCallsThatRanAtTheSameMomentOverlap(): void
    {
        $this->record(1, 'start', ['pid' => 100, 'at' => 1.0]);
        $this->record(1, 'end', ['pid' => 100, 'at' => 3.0]);
        $this->record(2, 'start', ['pid' => 101, 'at' => 2.0]);
        $this->record(2, 'end', ['pid' => 101, 'at' => 4.0]);

        $calls = CallRecords::in($this->directory);

        self::assertSame([2, 2, 2], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
        self::assertSame([100, 101], $calls->processes());
    }

    public function testACallThatBeganAsAnotherEndedDoesNotOverlapIt(): void
    {
        $this->record(1, 'start', ['pid' => 100, 'at' => 1.0]);
        $this->record(1, 'end', ['pid' => 100, 'at' => 2.0]);
        $this->record(2, 'start', ['pid' => 100, 'at' => 2.0]);
        $this->record(2, 'end', ['pid' => 100, 'at' => 3.0]);

        self::assertSame(1, CallRecords::in($this->directory)->mostAtOnce());
    }

    public function testACallWithoutAnEndCountsAsRunningToTheEnd(): void
    {
        $this->record(1, 'start', ['pid' => 100, 'at' => 1.0]);
        $this->record(2, 'start', ['pid' => 101, 'at' => 5.0]);
        $this->record(2, 'end', ['pid' => 101, 'at' => 6.0]);

        $calls = CallRecords::in($this->directory);

        self::assertSame([2, 1, 2], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
    }

    public function testADirectoryWithoutCallsHoldsNothing(): void
    {
        $calls = CallRecords::in($this->directory);

        self::assertSame([0, 0, 0], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
    }

    public function testARecordWithoutAProcessIsRefused(): void
    {
        $this->record(1, 'start', ['at' => 1.0]);

        $this->expectException(RuntimeException::class);

        CallRecords::in($this->directory);
    }

    // Forced by hand, because a writer that creates its file before it writes leaves this moment open only briefly: the name is there, the record is not yet.
    public function testAStartRecordThatIsStillEmptyIsNotCountedYet(): void
    {
        $this->record(1, 'start', ['pid' => 100, 'at' => 1.0]);
        touch($this->directory.'/calls/2.start');

        $calls = CallRecords::in($this->directory);

        self::assertSame([1, 0], [$calls->started(), $calls->ended()]);
    }

    public function testACallWhoseEndRecordIsStillEmptyIsStillRunning(): void
    {
        $this->record(1, 'start', ['pid' => 100, 'at' => 1.0]);
        touch($this->directory.'/calls/1.end');

        $calls = CallRecords::in($this->directory);

        self::assertSame([1, 0], [$calls->started(), $calls->ended()]);
    }

    public function testARecordWithoutTheStartOfItsProcessIsRefused(): void
    {
        file_put_contents($this->directory.'/calls/1.start', json_encode(['pid' => 100, 'at' => 1.0], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);

        CallRecords::in($this->directory);
    }

    public function testEachWorkerIsNamedOnceByItsNumberAndStart(): void
    {
        $this->record(1, 'start', ['pid' => 100, 'started' => 7, 'at' => 1.0]);
        $this->record(2, 'start', ['pid' => 100, 'started' => 7, 'at' => 2.0]);
        $this->record(3, 'start', ['pid' => 100, 'started' => 9, 'at' => 3.0]);

        self::assertEquals([new RecordedProcess(100, 7), new RecordedProcess(100, 9)], CallRecords::in($this->directory)->workers());
    }

    public function testARecordThatIsNoJsonIsRefused(): void
    {
        file_put_contents($this->directory.'/calls/1.start', 'not json');

        $this->expectException(RuntimeException::class);

        CallRecords::in($this->directory);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function record(int $call, string $kind, array $data): void
    {
        file_put_contents($this->directory.'/calls/'.$call.'.'.$kind, json_encode(['started' => 1, ...$data], JSON_THROW_ON_ERROR));
    }
}
