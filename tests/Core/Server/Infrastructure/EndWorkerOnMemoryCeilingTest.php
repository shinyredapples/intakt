<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\EndWorkerOnMemoryCeiling;
use Intakt\Core\Server\Infrastructure\MemoryReading;
use Intakt\Core\Server\Infrastructure\WorkerExit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(EndWorkerOnMemoryCeiling::class)]
final class EndWorkerOnMemoryCeilingTest extends TestCase
{
    private const int MEGABYTE = 1_048_576;
    private const int CEILING_MEGABYTES = 100;

    public function testAWorkerWhoseMemoryPassesTheCeilingEndsItsLoop(): void
    {
        $exit = $this->createMock(WorkerExit::class);
        $exit->expects(self::once())->method('end');

        $this->terminate(100 * self::MEGABYTE + 1, $exit, worker: true);
    }

    public function testAWorkerBelowTheCeilingKeepsServing(): void
    {
        $exit = $this->createMock(WorkerExit::class);
        $exit->expects(self::never())->method('end');

        $this->terminate(50 * self::MEGABYTE, $exit, worker: true);
    }

    public function testAWorkerExactlyAtTheCeilingKeepsServing(): void
    {
        $exit = $this->createMock(WorkerExit::class);
        $exit->expects(self::never())->method('end');

        $this->terminate(100 * self::MEGABYTE, $exit, worker: true);
    }

    public function testAProcessThatIsNoWorkerIsNeverEnded(): void
    {
        $exit = $this->createMock(WorkerExit::class);
        $exit->expects(self::never())->method('end');

        $this->terminate(500 * self::MEGABYTE, $exit, worker: false);
    }

    private function terminate(int $bytes, WorkerExit $exit, bool $worker): void
    {
        $memory = self::createStub(MemoryReading::class);
        $memory->method('bytes')->willReturn($bytes);
        $request = new Request(server: $worker ? ['FRANKENPHP_WORKER' => '1'] : []);

        (new EndWorkerOnMemoryCeiling($memory, $exit, self::CEILING_MEGABYTES))(
            new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response()),
        );
    }
}
