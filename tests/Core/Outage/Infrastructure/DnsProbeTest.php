<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Infrastructure\DnsProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DnsProbe::class)]
final class DnsProbeTest extends TestCase
{
    public function testAHostTheSystemResolvesAnswers(): void
    {
        self::assertTrue(new DnsProbe('localhost', 3.0)->answers());
    }

    public function testAFailedLookupCountsAsNoAnswer(): void
    {
        self::assertFalse(new DnsProbe('localhost', 3.0, '<?php exit(1);')->answers());
    }

    public function testALookupSlowerThanTheTimeoutIsStoppedAtTheTimeout(): void
    {
        $started = hrtime(true);

        $answers = new DnsProbe('localhost', 0.5, '<?php sleep(30);')->answers();

        self::assertFalse($answers);
        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
    }
}
