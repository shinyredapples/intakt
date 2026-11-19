<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CallRecords::class)]
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
        $this->call(1, 100, 1.0, 3.0);
        $this->call(2, 101, 2.0, 4.0);

        $calls = CallRecords::in($this->directory);

        self::assertSame([2, 2, 2], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
        self::assertSame([100, 101], $calls->processes());
    }

    public function testACallThatBeganAsAnotherEndedDoesNotOverlapIt(): void
    {
        $this->call(1, 100, 1.0, 2.0);
        $this->call(2, 100, 2.0, 3.0);

        self::assertSame(1, CallRecords::in($this->directory)->mostAtOnce());
    }

    public function testACallWithoutAnEndCountsAsRunningToTheEnd(): void
    {
        $this->call(1, 100, 1.0, null);
        $this->call(2, 101, 5.0, 6.0);

        $calls = CallRecords::in($this->directory);

        self::assertSame([2, 1, 2], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
    }

    public function testADirectoryWithoutCallsHoldsNothing(): void
    {
        $calls = CallRecords::in($this->directory);

        self::assertSame([0, 0, 0], [$calls->started(), $calls->ended(), $calls->mostAtOnce()]);
    }

    private function call(int $call, int $pid, float $start, ?float $end): void
    {
        file_put_contents($this->directory.'/calls/'.$call.'.start', json_encode(['pid' => $pid, 'at' => $start], JSON_THROW_ON_ERROR));

        if (null !== $end) {
            file_put_contents($this->directory.'/calls/'.$call.'.end', json_encode(['pid' => $pid, 'at' => $end], JSON_THROW_ON_ERROR));
        }
    }
}
