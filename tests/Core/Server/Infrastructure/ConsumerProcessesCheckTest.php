<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\ConsumerProcessesCheck;
use Intakt\Core\Server\Infrastructure\DoctorCheckVerdict;
use Intakt\Core\Server\Infrastructure\WorkerProcess;
use Intakt\Tests\Core\Server\FakeProcessRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerProcessesCheck::class)]
#[CoversClass(DoctorCheckVerdict::class)]
#[CoversClass(WorkerProcess::class)]
#[UsesClass(FakeProcessRoot::class)]
final class ConsumerProcessesCheckTest extends TestCase
{
    private const string MISSING_FIX = 'restart the container with docker compose restart intakt; outside the container, start them with messenger:consume async and messenger:consume scheduler_watcher scheduler_retention';
    private const string CANNOT_RUN_FIX = 'run the doctor inside the container: docker compose exec intakt php bin/console intakt:doctor';
    private const string UNVERIFIED_FIX = 'run the doctor as the user that runs the workers, so it may read their working directory (in the compose stack: docker compose exec intakt php bin/console intakt:doctor)';

    private ?FakeProcessRoot $root = null;

    public function testRunningAsyncAndSchedulerConsumersPass(): void
    {
        $verdict = $this->check(FakeProcessRoot::healthy());

        self::assertTrue($verdict->passes());
        self::assertNull($verdict->fix);
    }

    public function testAMissingAsyncConsumerFailsWithItsFixText(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher', 'scheduler_retention'],
        ]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
        self::assertSame(self::MISSING_FIX, $verdict->fix);
    }

    public function testAMissingSchedulerConsumerFailsWithItsFixText(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async', '--quiet'],
            ['php', 'bin/console', 'messenger:consume', 'async', '--quiet'],
        ]));

        self::assertSame('the scheduler worker is not running, so pull requests are not watched', $verdict->problem);
        self::assertSame(self::MISSING_FIX, $verdict->fix);
    }

    public function testNoConsumerAtAllNamesBothMissingKindsOnOneProblem(): void
    {
        $verdict = $this->check(new FakeProcessRoot([['frankenphp', 'run'], ['php', 'bin/console', 'intakt:doctor']]));

        self::assertSame('no async worker is running, so no review or build moves; the scheduler worker is not running, so pull requests are not watched', $verdict->problem);
    }

    public function testAReceiverNameBeforeTheConsumeCommandDoesNotCountAsAConsumer(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['grep', 'async', 'messenger:consume'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
    }

    public function testAGrepSearchingForTheWorkerDoesNotCountAsTheWorker(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['grep', '-e', 'messenger:consume', '-e', 'async'],
            ['grep', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
    }

    public function testAConsumerStartedWithAFullPhpPathAndIniOptionsCounts(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['/usr/local/bin/php', '-d', 'memory_limit=1G', FakeProcessRoot::INSTALL.'/bin/console', 'messenger:consume', 'async'],
            ['php8.5', 'bin/console', 'messenger:consume', 'scheduler_watcher', 'scheduler_retention'],
        ], [0 => null]));

        self::assertTrue($verdict->passes());
    }

    public function testAnUnrelatedScriptNamedConsoleDoesNotCountAsAWorker(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', '/elsewhere/console', 'messenger:consume', 'async'],
            ['php', FakeProcessRoot::OTHER_INSTALL.'/bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
    }

    public function testAConsumerOfAnotherInstallDoesNotCountAsAWorker(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ], [0 => FakeProcessRoot::OTHER_INSTALL]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
    }

    public function testAConsumerWhoseWorkingDirectoryCannotBeReadFailsAsCannotRunInsteadOfStopped(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ], [1 => null]));

        self::assertSame('cannot run (the doctor may not read the working directory of a process that may be the scheduler_watcher worker)', $verdict->problem);
        self::assertSame(self::UNVERIFIED_FIX, $verdict->fix);
    }

    public function testAnUnverifiableConsumerFailsOnlyItsOwnReceiver(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', FakeProcessRoot::INSTALL.'/bin/console', 'messenger:consume', 'scheduler_watcher'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
            ['php', 'bin/console', 'messenger:consume', 'async'],
        ], [1 => null, 2 => null]));

        self::assertSame('cannot run (the doctor may not read the working directory of a process that may be the async worker)', $verdict->problem);
        self::assertSame(self::UNVERIFIED_FIX, $verdict->fix);
    }

    public function testAVerifiedConsumerPassesBesideAnUnverifiableOneForTheSameReceiver(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ], [0 => null]));

        self::assertTrue($verdict->passes());
    }

    public function testAnUnverifiableConsumerAndAMissingOneAreBothNamed(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async'],
        ], [0 => null]));

        self::assertSame('cannot run (the doctor may not read the working directory of a process that may be the async worker); the scheduler worker is not running, so pull requests are not watched', $verdict->problem);
        self::assertSame(self::UNVERIFIED_FIX.'; '.self::MISSING_FIX, $verdict->fix);
    }

    public function testAnUnreadableWorkingDirectoryOfAProcessThatIsNotAConsoleStillReportsTheWorkerStopped(): void
    {
        $verdict = $this->check(new FakeProcessRoot([
            ['php', 'worker.php', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ], [0 => null]));

        self::assertSame('no async worker is running, so no review or build moves', $verdict->problem);
        self::assertSame(self::MISSING_FIX, $verdict->fix);
    }

    public function testAConsumerStartedThroughALinkToTheInstallCounts(): void
    {
        $this->root = new FakeProcessRoot([
            ['php', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ]);
        symlink($this->root->install, $this->root->path.'/linked');

        $verdict = new ConsumerProcessesCheck($this->root->path.'/linked', $this->root->path)->run();

        self::assertTrue($verdict->passes());
    }

    public function testAnInstallWithoutItsConsoleCountsNoWorker(): void
    {
        $this->root = FakeProcessRoot::healthy();

        $verdict = new ConsumerProcessesCheck($this->root->path.'/no-install', $this->root->path)->run();

        self::assertSame(self::MISSING_FIX, $verdict->fix);
    }

    public function testEveryCommandLineUnreadableFailsAsCannotRun(): void
    {
        $this->root = FakeProcessRoot::healthy();
        $files = glob($this->root->path.'/[0-9]*/cmdline');
        self::assertNotFalse($files);
        foreach ($files as $file) {
            chmod($file, 0o000);
        }

        $verdict = $this->root->check()->run();

        self::assertStringStartsWith('cannot run (the process list under ', (string) $verdict->problem);
        self::assertSame(self::CANNOT_RUN_FIX, $verdict->fix);
    }

    public function testTheDoctorsOwnReadableCommandLineBesideHiddenOnesStillFailsAsCannotRun(): void
    {
        $this->root = new FakeProcessRoot([
            ['php', 'bin/console', 'intakt:doctor'],
            ['php', 'bin/console', 'messenger:consume', 'async'],
            ['php', 'bin/console', 'messenger:consume', 'scheduler_watcher'],
        ]);
        chmod($this->root->path.'/101/cmdline', 0o000);
        chmod($this->root->path.'/102/cmdline', 0o000);

        $verdict = new ConsumerProcessesCheck($this->root->install, $this->root->path, 100)->run();

        self::assertStringStartsWith('cannot run (the process list under ', (string) $verdict->problem);
        self::assertSame(self::CANNOT_RUN_FIX, $verdict->fix);
    }

    public function testOneReadableEmptyCommandLineBesideUnreadableOnesReportsTheMissingWorkers(): void
    {
        $this->root = new FakeProcessRoot([[''], ['php', 'bin/console', 'messenger:consume', 'async']]);
        chmod($this->root->path.'/101/cmdline', 0o000);

        $verdict = $this->root->check()->run();

        self::assertSame('no async worker is running, so no review or build moves; the scheduler worker is not running, so pull requests are not watched', $verdict->problem);
    }

    public function testAMissingProcessRootFailsAsCannotRun(): void
    {
        $verdict = new ConsumerProcessesCheck(sys_get_temp_dir(), sys_get_temp_dir().'/intakt-no-such-proc')->run();

        self::assertSame('cannot run (the process list under '.sys_get_temp_dir().'/intakt-no-such-proc cannot be read)', $verdict->problem);
        self::assertSame(self::CANNOT_RUN_FIX, $verdict->fix);
    }

    public function testAnUnreadableProcessRootFailsAsCannotRun(): void
    {
        $this->root = FakeProcessRoot::healthy();
        chmod($this->root->path, 0o000);

        $verdict = $this->root->check()->run();

        self::assertStringStartsWith('cannot run (the process list under ', (string) $verdict->problem);
    }

    public function testAProcessRootWithoutAnyProcessFailsAsCannotRun(): void
    {
        $verdict = $this->check(new FakeProcessRoot([]));

        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
        self::assertSame(self::CANNOT_RUN_FIX, $verdict->fix);
    }

    protected function tearDown(): void
    {
        $this->root?->remove();
    }

    private function check(FakeProcessRoot $root): DoctorCheckVerdict
    {
        $this->root = $root;

        return $root->check()->run();
    }
}
