<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\TestPorts;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestPorts::class)]
final class TestPortsTest extends TestCase
{
    public function testWithoutAnInheritedRangeEachWorkerGetsOneHundredPortsFrom20000(): void
    {
        $ports = TestPorts::forWorkers(2, null);

        self::assertNotNull($ports);
        self::assertSame('20000-20099', $ports->block(0));
        self::assertSame('20100-20199', $ports->block(1));
    }

    public function testAGateRunInsideATestWorkerSplitsTheRangeItInheritsAmongItsWorkers(): void
    {
        $ports = TestPorts::forWorkers(2, '20100-20199');

        self::assertNotNull($ports);
        self::assertSame('20100-20149', $ports->block(0));
        self::assertSame('20150-20199', $ports->block(1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rangesThatAreNotRanges(): iterable
    {
        yield 'no numbers' => ['ports'];
        yield 'reversed' => ['20199-20100'];
        yield 'port zero' => ['0-99'];
        yield 'past the last port' => ['65500-65600'];
        yield 'one number' => ['20100'];
    }

    #[DataProvider('rangesThatAreNotRanges')]
    public function testAnInheritedRangeThatIsNotARangeOfPortsIsRefused(string $range): void
    {
        self::assertNull(TestPorts::forWorkers(1, $range));
    }

    public function testAnInheritedRangeTooSmallToGiveEachWorkerAPortIsRefused(): void
    {
        self::assertNull(TestPorts::forWorkers(2, '20100-20100'));
    }

    public function testSoManyWorkersThatTheirPortsWouldReachThePortsTheSystemPicksAreRefused(): void
    {
        self::assertNull(TestPorts::forWorkers(128, null));
        self::assertNotNull(TestPorts::forWorkers(127, null));
    }

    public function testAWorkerBeyondTheCountHasNoBlock(): void
    {
        $ports = TestPorts::forWorkers(2, '20100-20199');

        self::assertNotNull($ports);
        $this->expectException(LogicException::class);

        $ports->block(2);
    }
}
