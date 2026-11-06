<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\EndWorkerOnMemoryCeiling;
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
        $this->terminate(
            100 * self::MEGABYTE + 1,
            worker: true,
            ended: true,
        );
    }

    public function testAWorkerBelowTheCeilingKeepsServing(): void
    {
        $this->terminate(
            50 * self::MEGABYTE,
            worker: true,
            ended: false,
        );
    }

    public function testAWorkerExactlyAtTheCeilingKeepsServing(): void
    {
        $this->terminate(
            100 * self::MEGABYTE,
            worker: true,
            ended: false,
        );
    }

    public function testAProcessThatIsNoWorkerIsNeverEnded(): void
    {
        $this->terminate(
            500 * self::MEGABYTE,
            worker: false,
            ended: false,
        );
    }

    private function terminate(int $bytes, bool $worker, bool $ended): void
    {
        $calls = 0;
        $request = new Request(server: $worker ? ['FRANKENPHP_WORKER' => '1'] : []);

        (new EndWorkerOnMemoryCeiling(
            self::CEILING_MEGABYTES,
            static fn (): int => $bytes,
            static function () use (&$calls): void {
                ++$calls;
            },
        ))(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response()));

        self::assertSame($ended ? 1 : 0, $calls);
    }
}
