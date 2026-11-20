<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Infrastructure\DnsProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(DnsProbe::class)]
final class DnsProbeTest extends TestCase
{
    public function testAnAddressLiteralAnswersWithoutAResolver(): void
    {
        self::assertTrue(new DnsProbe('127.0.0.1', 3.0)->answers());
    }

    public function testAFailedLookupCountsAsNoAnswer(): void
    {
        self::assertFalse(new DnsProbe('localhost', 3.0, '<?php exit(1);')->answers());
    }

    public function testALookupThatCannotRunCountsAsNoAnswer(): void
    {
        $before = getenv('PHP_BINARY');
        putenv('PHP_BINARY=intakt-no-such-php');

        try {
            self::assertFalse(new DnsProbe('localhost', 3.0)->answers());
        } finally {
            putenv(false === $before ? 'PHP_BINARY' : 'PHP_BINARY='.$before);
        }
    }

    public function testALookupWhoseProcessCannotBeBuiltCountsAsNoAnswer(): void
    {
        $probing = <<<'PHP'
            require $argv[1];
            exit(new Intakt\Core\Outage\Infrastructure\DnsProbe('127.0.0.1', 3.0)->answers() ? 1 : 0);
            PHP;

        $withoutProcOpen = new Process([PHP_BINARY, '-d', 'disable_functions=proc_open', '-r', $probing, dirname(__DIR__, 4).'/vendor/autoload.php']);

        self::assertSame(0, $withoutProcOpen->run(), $withoutProcOpen->getErrorOutput().$withoutProcOpen->getOutput());
    }

    public function testALookupSlowerThanTheTimeoutIsStoppedAtTheTimeout(): void
    {
        $started = hrtime(true);

        $answers = new DnsProbe('localhost', 0.5, '<?php sleep(30);')->answers();

        self::assertFalse($answers);
        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
    }
}
