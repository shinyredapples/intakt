<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\InfrastructureCheck;
use Intakt\Core\Server\Contract\WorkerThreads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfrastructureCheck::class)]
#[UsesClass(WorkerThreads::class)]
#[UsesClass(EndpointDown::class)]
final class InfrastructureCheckTest extends TestCase
{
    public function testAPassedCheckPassesAndPresentsWhatItRead(): void
    {
        $check = InfrastructureCheck::passed(new WorkerThreads(2, 1));

        self::assertTrue($check->passes());
        self::assertSame('2/1', $check->presented($this->read(...), $this->down(...)));
    }

    public function testAFailedCheckWithAReadingFailsAndStillPresentsWhatItRead(): void
    {
        $check = InfrastructureCheck::failed(new WorkerThreads(0, 0), 'no worker thread is ready', 'look at the log');

        self::assertFalse($check->passes());
        self::assertSame('0/0', $check->presented($this->read(...), $this->down(...)));
    }

    public function testACheckWhoseSourceIsUnreadableFailsAndPresentsTheReasonInsteadOfAReading(): void
    {
        $check = InfrastructureCheck::unreadable(new EndpointDown('/frankenphp/threads did not answer'), 'worker threads down', 'start it');

        self::assertFalse($check->passes());
        self::assertSame('down: /frankenphp/threads did not answer', $check->presented($this->read(...), $this->down(...)));
    }

    private function read(WorkerThreads $threads): string
    {
        return $threads->busy.'/'.$threads->idle;
    }

    private function down(string $reason): string
    {
        return 'down: '.$reason;
    }
}
