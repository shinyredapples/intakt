<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once __DIR__.'/../../bin/probe';

// Loads bin/probe for its classes: the decision needs no namespace privileges.
#[CoversNothing]
#[Group('instance')]
final class ProbeIsolationTest extends TestCase
{
    private const string HEADER = "Inter-|   Receive\n face |bytes\n";

    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-probe-isolation-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    public function testWithoutTheVariableTheInstanceStartsInsideUnshare(): void
    {
        $command = new \Probe('/project', false, $this->network(['lo'], true))->command('tests/Probe/A.php');

        self::assertSame('unshare', $command[0]);
        self::assertContains('--net', $command);
        self::assertContains('/project/bin/instance', $command);
    }

    public function testWithTheVariableTheInstanceStartsDirectlyWithoutUnshare(): void
    {
        $command = new \Probe('/project', true, $this->network(['lo'], true))->command('tests/Probe/ATest.php');

        self::assertSame([PHP_BINARY, '/project/bin/instance', '--'], array_slice($command, 0, 3));
        self::assertNotContains('unshare', $command);
        self::assertContains('tests/Probe/ATest.php', $command);
    }

    public function testOnlyLoopbackThatIsUpCountsAsOffline(): void
    {
        self::assertNull($this->network(['lo'], true)->offlineProblem());
    }

    public function testAnotherInterfaceCountsAsOnline(): void
    {
        self::assertStringContainsString('eth0', (string) $this->network(['lo', 'eth0'], true)->offlineProblem());
    }

    public function testALoopbackThatIsDownCountsAsOnline(): void
    {
        self::assertStringContainsString('down', (string) $this->network(['lo'], false)->offlineProblem());
    }

    public function testTheProbeRefusesWhenIsolatedButOnline(): void
    {
        $refusal = new \Probe('/project', true, $this->network(['lo', 'eth0'], true))->refusal();

        self::assertStringContainsString('PROBE_ISOLATED is set but the run is not offline', (string) $refusal);
    }

    public function testTheProbeNeverRefusesWithoutTheVariable(): void
    {
        self::assertNull(new \Probe('/project', false, $this->network(['lo', 'eth0'], true))->refusal());
    }

    /**
     * @param list<string> $names
     */
    private function network(array $names, bool $loopbackUp): \Network
    {
        $devices = self::HEADER;

        foreach ($names as $name) {
            $devices .= '  '.$name.": 0 0\n";
        }

        new Filesystem()->dumpFile($this->scratch.'/dev', $devices);

        // A documentation address is on no local interface, so binding to it fails as it does on a down loopback.
        return new \Network($this->scratch.'/dev', $loopbackUp ? '127.0.0.1:0' : '192.0.2.1:0');
    }
}
