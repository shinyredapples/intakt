<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ReadBuildUsage;

use Intakt\Core\Build\Domain\BuildUsage;
use Intakt\Core\Build\Domain\SessionLog;
use Intakt\Core\Build\Domain\SessionUsage;
use Intakt\Core\Build\Domain\UsageReading;
use Intakt\Core\Build\UseCase\ReadBuildUsage\ReadBuildUsageCommand;
use Intakt\Core\Build\UseCase\ReadBuildUsage\ReadBuildUsageInput;
use Intakt\Core\Build\UseCase\RunBuildJob\IsolatedSessionLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ReadBuildUsageCommand::class)]
#[CoversClass(ReadBuildUsageInput::class)]
#[UsesClass(IsolatedSessionLog::class)]
#[UsesClass(SessionLog::class)]
#[UsesClass(SessionUsage::class)]
#[UsesClass(BuildUsage::class)]
#[UsesClass(UsageReading::class)]
final class ReadBuildUsageCommandTest extends TestCase
{
    private const string LOGS = __DIR__.'/../../Fixtures/build-logs';

    private string $folder = '';

    public function testTheSampleLogPrintsEachCountAndTheTotal(): void
    {
        $tester = $this->usageCommand(self::LOGS.'/sample.log');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("reading read\ninput_tokens 18234\noutput_tokens 9120\ncache_read_input_tokens 1543210\ncache_creation_input_tokens 88412\ntotal 1658976\n", $tester->getDisplay());
    }

    public function testAMalformedLogIsRefusedWithItsReading(): void
    {
        $tester = $this->usageCommand(self::LOGS.'/malformed.log');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('usage not read: malformed', $tester->getDisplay());
    }

    public function testALogOverTheByteLimitIsRefusedBeforeItIsRead(): void
    {
        new Filesystem()->dumpFile($this->folder.'/build.log', str_repeat("x\n", intdiv(IsolatedSessionLog::MAX_BYTES, 2) + 1));

        $tester = $this->usageCommand($this->folder.'/build.log');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('usage not read: too_large', $tester->getDisplay());
    }

    public function testACutLogWithoutAResultLinePrintsTheUsageOfItsMessages(): void
    {
        $tester = $this->usageCommand(self::LOGS.'/cut.log');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("reading read\ninput_tokens 5500\noutput_tokens 1000\ncache_read_input_tokens 435000\ncache_creation_input_tokens 19000\ntotal 460500\n", $tester->getDisplay());
    }

    public function testALogThatGrowsPastTheByteLimitBetweenTheCheckAndTheReadIsRefused(): void
    {
        new Filesystem()->dumpFile($this->folder.'/build.log', (string) file_get_contents(self::LOGS.'/sample.log'));
        $read = new ReadBytes();
        stream_context_set_default([GrowingLog::PROTOCOL => ['read' => $read]]);
        stream_wrapper_register(GrowingLog::PROTOCOL, GrowingLog::class);

        try {
            $tester = $this->usageCommand(GrowingLog::PROTOCOL.'://'.$this->folder.'/build.log');
        } finally {
            stream_wrapper_unregister(GrowingLog::PROTOCOL);
            stream_context_set_default([GrowingLog::PROTOCOL => ['read' => null]]);
        }

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('usage not read: too_large', $tester->getDisplay());
        self::assertGreaterThan(IsolatedSessionLog::MAX_BYTES + 1, filesize($this->folder.'/build.log'));
        // Plus one chunk, because PHP's stream layer asks the wrapper for whole 8 KiB chunks and keeps what the command did not take.
        self::assertLessThanOrEqual(IsolatedSessionLog::MAX_BYTES + 1 + 8192, $read->total());
    }

    public function testALinkToALogIsNeverRead(): void
    {
        new Filesystem()->symlink(realpath(self::LOGS.'/sample.log') ?: '', $this->folder.'/build.log');

        $tester = $this->usageCommand($this->folder.'/build.log');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not a regular file', $this->unwrapped($tester));
    }

    public function testAFolderOrAMissingPathIsNeverRead(): void
    {
        self::assertSame(Command::FAILURE, $this->usageCommand($this->folder)->getStatusCode());
        self::assertSame(Command::FAILURE, $this->usageCommand($this->folder.'/missing.log')->getStatusCode());
    }

    public function testAnUnreadableLogFailsWithoutAUsage(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('The superuser reads a file without read permission.');
        }
        new Filesystem()->dumpFile($this->folder.'/build.log', 'x');
        chmod($this->folder.'/build.log', 0o000);

        $tester = $this->usageCommand($this->folder.'/build.log');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('could not be read', $this->unwrapped($tester));
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-build-usage-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function usageCommand(string $log): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ReadBuildUsageCommand(new IsolatedSessionLog(__DIR__.'/../../../../../vendor/autoload.php')));
        $tester = new CommandTester($application->find('intakt:build:usage'));
        $tester->execute(['log' => $log]);

        return $tester;
    }

    // The error block wraps a long path, so its words are compared without the wrapping.
    private function unwrapped(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
