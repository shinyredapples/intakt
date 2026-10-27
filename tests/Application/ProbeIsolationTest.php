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
        $probe = new Probe('/project', '1' === $argv[2], new Network($argv[3], $argv[4]));
        echo json_encode(['command' => $probe->command('tests/Probe/ATest.php'), 'refusal' => $probe->refusal()]);
        PHP;

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
        $decision = $this->decide(false, ['lo'], true);

        self::assertSame('unshare', $decision['command'][0]);
        self::assertContains('--net', $decision['command']);
        self::assertContains('/project/bin/instance', $decision['command']);
        self::assertNull($decision['refusal']);
    }

    public function testWithoutTheVariableAnOnlineHostIsNotRefused(): void
    {
        self::assertNull($this->decide(false, ['lo', 'eth0'], true)['refusal']);
    }

    public function testWithTheVariableTheInstanceStartsDirectlyWithoutUnshare(): void
    {
        $decision = $this->decide(true, ['lo'], true);

        self::assertSame([PHP_BINARY, '/project/bin/instance', '--'], array_slice($decision['command'], 0, 3));
        self::assertNotContains('unshare', $decision['command']);
        self::assertContains('tests/Probe/ATest.php', $decision['command']);
        self::assertNull($decision['refusal']);
    }

    public function testWithTheVariableAnotherInterfaceIsRefused(): void
    {
        $refusal = $this->decide(true, ['lo', 'eth0'], true)['refusal'];

        self::assertStringContainsString('PROBE_ISOLATED is set but the run is not offline', (string) $refusal);
        self::assertStringContainsString('eth0', (string) $refusal);
    }

    public function testWithTheVariableALoopbackThatIsDownIsRefused(): void
    {
        self::assertStringContainsString('lo is down', (string) $this->decide(true, ['lo'], false)['refusal']);
    }

    public function testWithTheVariableAnUnreadableInterfaceListIsRefused(): void
    {
        $refusal = $this->decideOn(true, $this->scratch.'/missing', '127.0.0.1:0')['refusal'];

        self::assertStringContainsString('the network interfaces cannot be read', (string) $refusal);
    }

    public function testWithTheVariableAMalformedInterfaceListIsRefused(): void
    {
        new Filesystem()->dumpFile($this->scratch.'/dev', "Inter-|   Receive\n face |bytes\n  not an interface line\n");

        $refusal = $this->decideOn(true, $this->scratch.'/dev', '127.0.0.1:0')['refusal'];

        self::assertStringContainsString('the network interfaces cannot be read', (string) $refusal);
    }

    public function testWithTheVariableAnEmptyInterfaceListIsRefused(): void
    {
        new Filesystem()->dumpFile($this->scratch.'/dev', "Inter-|   Receive\n face |bytes\n");

        $refusal = $this->decideOn(true, $this->scratch.'/dev', '127.0.0.1:0')['refusal'];

        self::assertStringContainsString('the network interfaces cannot be read', (string) $refusal);
    }

    /**
     * @param list<string> $names
     *
     * @return array{command: list<string>, refusal: ?string}
     */
    private function decide(bool $isolated, array $names, bool $loopbackUp): array
    {
        $devices = "Inter-|   Receive\n face |bytes\n";

        foreach ($names as $name) {
            $devices .= '  '.$name.": 0 0\n";
        }

        new Filesystem()->dumpFile($this->scratch.'/dev', $devices);

        // A documentation address is on no local interface, so binding to it fails as it does on a down loopback.
        return $this->decideOn($isolated, $this->scratch.'/dev', $loopbackUp ? '127.0.0.1:0' : '192.0.2.1:0');
    }

    /**
     * @return array{command: list<string>, refusal: ?string}
     */
    private function decideOn(bool $isolated, string $devices, string $loopback): array
    {
        $process = new Process([PHP_BINARY, '-r', self::DECIDE, dirname(__DIR__, 2), $isolated ? '1' : '0', $devices, $loopback]);
        $process->mustRun();

        /** @var array{command: list<string>, refusal: ?string} $decision */
        $decision = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        return $decision;
    }
}
