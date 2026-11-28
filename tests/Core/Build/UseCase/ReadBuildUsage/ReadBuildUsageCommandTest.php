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

    public function testALinkToALogIsNeverRead(): void
    {
        new Filesystem()->symlink((string) realpath(self::LOGS.'/sample.log'), $this->folder.'/build.log');

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
        $application->addCommand(new ReadBuildUsageCommand(new IsolatedSessionLog(__DIR__.'/../../../../../vendor/autoload.php'), new Filesystem()));
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
