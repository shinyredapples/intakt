<?php

declare(strict_types=1);

namespace Intakt\Tests\Support;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPorts::class)]
final class WorkerPortsTest extends TestCase
{
    public function testThePortsComeFromTheRangeOfTheWorker(): void
    {
        $first = $this->freePortOfThisWorker();

        $port = new WorkerPorts($first.'-'.($first + 1))->next();

        self::assertContains($port, [$first, $first + 1]);
    }

    public function testTwoPortsOfOneTestDiffer(): void
    {
        $first = $this->freePortOfThisWorker();
        $ports = new WorkerPorts($first.'-'.($first + 50));

        self::assertNotSame($ports->next(), $ports->next());
    }

    public function testAPortSomeoneListensOnIsSkipped(): void
    {
        $first = $this->freePortOfThisWorker();
        $listener = stream_socket_server('tcp://127.0.0.1:'.$first);
        self::assertNotFalse($listener);

        try {
            self::assertGreaterThan($first, new WorkerPorts($first.'-'.($first + 50))->next());
        } finally {
            fclose($listener);
        }
    }

    public function testARangeWithNoFreePortLeftIsRefused(): void
    {
        $first = $this->freePortOfThisWorker();
        $listener = stream_socket_server('tcp://127.0.0.1:'.$first);
        self::assertNotFalse($listener);

        try {
            $this->expectExceptionObject(new LogicException('No free port is left in '.$first.'-'.$first.', the range of this test worker.'));

            new WorkerPorts($first.'-'.$first)->next();
        } finally {
            fclose($listener);
        }
    }

    public function testARangeThatIsNotTwoPortsIsRefused(): void
    {
        $this->expectExceptionObject(new LogicException('INTAKT_TEST_PORTS must name a range of ports such as 20000-20099, not "20000".'));

        new WorkerPorts('20000');
    }

    public function testARangeThatRunsBackwardsIsRefused(): void
    {
        $this->expectExceptionObject(new LogicException('INTAKT_TEST_PORTS must name a range of ports such as 20000-20099, not "20099-20000".'));

        new WorkerPorts('20099-20000');
    }

    public function testWithoutARangeTheSystemPicksTheFreePort(): void
    {
        self::assertGreaterThan(0, new WorkerPorts(null)->next());
    }

    private function freePortOfThisWorker(): int
    {
        return WorkerPorts::ofThisWorker()->next();
    }
}
