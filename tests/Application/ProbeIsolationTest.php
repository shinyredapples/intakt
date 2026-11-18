<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The decision runs in a child that loads bin/probe for its classes (a direct run starts a probe, a load does not), so it needs no namespace privileges.
#[CoversNothing]
#[Group('instance')]
final class ProbeIsolationTest extends TestCase
{
    private const string DECIDE = <<<'PHP'
        require $argv[1].'/bin/probe';
        $probe = new Probe('/project', Isolation::fromVariable((string) getenv('PROBE_ISOLATED')), new Network($argv[2], $argv[3]));
        echo json_encode(['command' => $probe->command('tests/Probe/ATest.php'), 'refusal' => $probe->refusal()]);
        PHP;

    private const string LOOPBACK_UP = '127.0.0.1:0';

    // A down lo needs namespace privileges, so a documentation address (on no local interface) fails the check at the bind instead of the connection.
    private const string LOOPBACK_DOWN = '192.0.2.1:0';

    private string $scratch;

    public function testWithoutTheVariableTheInstanceStartsInsideUnshare(): void
    {
        $decision = $this->decide(false, ['lo'], self::LOOPBACK_UP);

        self::assertSame('unshare', $decision['command'][0]);
        self::assertContains('--net', $decision['command']);
        self::assertContains('/project/bin/instance', $decision['command']);
        self::assertNull($decision['refusal']);
    }

    public function testWithoutTheVariableAnOnlineHostIsNotRefused(): void
    {
        self::assertNull($this->decide(false, ['lo', 'eth0'], self::LOOPBACK_UP)['refusal']);
    }

    public function testAnotherValueOfTheVariableKeepsUnshare(): void
    {
        $decision = $this->decide('yes', ['lo', 'eth0'], self::LOOPBACK_UP);

        self::assertSame('unshare', $decision['command'][0]);
        self::assertNull($decision['refusal']);
    }

    public function testWithTheVariableTheInstanceStartsDirectlyWithoutUnshare(): void
    {
        $decision = $this->decide('1', ['lo'], self::LOOPBACK_UP);

        self::assertSame([PHP_BINARY, '/project/bin/instance', '--'], array_slice($decision['command'], 0, 3));
        self::assertNotContains('unshare', $decision['command']);
        self::assertContains('tests/Probe/ATest.php', $decision['command']);
        self::assertNull($decision['refusal']);
    }

    public function testWithTheVariableAnotherInterfaceIsRefused(): void
    {
        $refusal = $this->decide('1', ['lo', 'eth0'], self::LOOPBACK_UP)['refusal'];

        self::assertStringContainsString('PROBE_ISOLATED is set but the run is not offline', (string) $refusal);
        self::assertStringContainsString('eth0', (string) $refusal);
    }

    public function testWithTheVariableALoopbackThatIsDownIsRefused(): void
    {
        self::assertStringContainsString('lo is down', (string) $this->decide('1', ['lo'], self::LOOPBACK_DOWN)['refusal']);
    }

    public function testWithTheVariableAnUnreadableInterfaceListIsRefused(): void
    {
        $refusal = $this->decideOn('1', $this->scratch.'/missing', self::LOOPBACK_UP)['refusal'];

        self::assertStringContainsString('the network interfaces cannot be read', (string) $refusal);
    }

    public function testWithTheVariableAMalformedInterfaceListIsRefused(): void
    {
        new Filesystem()->dumpFile($this->scratch.'/dev', "Inter-|   Receive\n face |bytes\n  not an interface line\n");

        $refusal = $this->decideOn('1', $this->scratch.'/dev', self::LOOPBACK_UP)['refusal'];

        self::assertStringContainsString('the network interfaces cannot be read', (string) $refusal);
    }

    public function testWithTheVariableAnEmptyInterfaceListIsRefused(): void
    {
        new Filesystem()->dumpFile($this->scratch.'/dev', "Inter-|   Receive\n face |bytes\n");

        $refusal = $this->decideOn('1', $this->scratch.'/dev', self::LOOPBACK_UP)['refusal'];

        self::assertStringContainsString('the network interfaces cannot be read', (string) $refusal);
    }

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().'/intakt-probe-isolation-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    /**
     * @param list<string> $names
     *
     * @return array{command: list<string>, refusal: ?string}
     */
    private function decide(string|false $variable, array $names, string $loopback): array
    {
        $devices = "Inter-|   Receive\n face |bytes\n";

        foreach ($names as $name) {
            $devices .= '  '.$name.": 0 0\n";
        }

        new Filesystem()->dumpFile($this->scratch.'/dev', $devices);

        return $this->decideOn($variable, $this->scratch.'/dev', $loopback);
    }

    /**
     * @return array{command: list<string>, refusal: ?string}
     */
    private function decideOn(string|false $variable, string $devices, string $loopback): array
    {
        // Process removes a variable whose value is false, so false runs the child as if PROBE_ISOLATED were never set.
        $process = new Process([PHP_BINARY, '-r', self::DECIDE, dirname(__DIR__, 2), $devices, $loopback], env: ['PROBE_ISOLATED' => $variable]);
        $process->mustRun();

        /** @var array{command: list<string>, refusal: ?string} $decision */
        $decision = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $decision;
    }
}
