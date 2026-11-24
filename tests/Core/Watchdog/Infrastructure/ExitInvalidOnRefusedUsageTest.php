<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Infrastructure\ExitInvalidOnRefusedUsage;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\Infrastructure\OwnerKey;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Core\Watchdog\Infrastructure\TrustedSignatures;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainCommand;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainInput;
use Intakt\Core\Watchdog\UseCase\CheckMain\TrustedKeys;
use Intakt\Core\Watchdog\UseCase\GuardMain\GuardMainCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\EventListener\ErrorListener;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Log\Logger;

#[CoversClass(ExitInvalidOnRefusedUsage::class)]
#[UsesClass(CheckMainCommand::class)]
#[UsesClass(CheckMain::class)]
#[UsesClass(CheckMainInput::class)]
#[UsesClass(TrustedKeys::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(MainHistory::class)]
#[UsesClass(TrustedSignatures::class)]
#[UsesClass(OwnerKey::class)]
#[UsesClass(OwnerKeyRefused::class)]
#[UsesClass(WatchedMain::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(MainNotRead::class)]
final class ExitInvalidOnRefusedUsageTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedUsages(): iterable
    {
        $head = str_repeat('a', 40);
        yield 'a recorded merge without its value' => [['repository' => '.', 'last-seen-head' => $head, '--recorded-merge' => null]];
        yield 'an owner key without its value' => [['repository' => '.', 'last-seen-head' => $head, '--owner-key' => null]];
        yield 'a peer key without its value' => [['repository' => '.', 'last-seen-head' => $head, '--peer-key' => null]];
        yield 'a missing last seen head' => [['repository' => '.']];
        yield 'an unknown option' => [['repository' => '.', 'last-seen-head' => $head, '--merge' => $head]];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedUsages')]
    public function testUsageTheConsoleRefusesForTheWatchdogCheckExitsWithTwo(array $input): void
    {
        $tester = $this->applicationTester();

        $exit = $tester->run(['command' => CheckMainCommand::NAME, ...$input]);

        self::assertSame(Command::INVALID, $exit);
    }

    public function testUsageTheConsoleRefusesForAnotherCommandKeepsItsExitCode(): void
    {
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new InvalidOptionException('refused'), new Command('intakt:other'));

        new ExitInvalidOnRefusedUsage()($event);

        self::assertSame(1, $event->getExitCode());
    }

    public function testUsageTheConsoleRefusesForTheWatchdogGuardExitsWithTwo(): void
    {
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new InvalidOptionException('refused'), new Command(GuardMainCommand::NAME));

        new ExitInvalidOnRefusedUsage()($event);

        self::assertSame(Command::INVALID, $event->getExitCode());
    }

    public function testAnErrorThatIsNotRefusedUsageKeepsItsExitCode(): void
    {
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('failed'), new Command(CheckMainCommand::NAME));

        new ExitInvalidOnRefusedUsage()($event);

        self::assertSame(1, $event->getExitCode());
    }

    public function testRefusedUsageOfTheWatchdogCheckLogsNoneOfItsCommandLine(): void
    {
        $log = $this->logStream();
        $tester = $this->applicationTester($log);

        $tester->run(['command' => CheckMainCommand::NAME, 'repository' => '.', 'last-seen-head' => str_repeat('a', 40), '--owner-key' => ['private-looking-marker'], '--recorded-merge' => null]);

        self::assertStringNotContainsString('private-looking-marker', $this->logged($log));
        self::assertStringContainsString('requires a value', $tester->getDisplay());
    }

    public function testAMalformedOwnerKeyTheCheckRefusesIsNotLogged(): void
    {
        $log = $this->logStream();
        $tester = $this->applicationTester($log);

        $exit = $tester->run(['command' => CheckMainCommand::NAME, 'repository' => '.', 'last-seen-head' => str_repeat('a', 40), '--owner-key' => ['private-looking-marker']]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringNotContainsString('private-looking-marker', $this->logged($log));
    }

    public function testAMalformedPeerKeyTheCheckRefusesIsNotLogged(): void
    {
        $log = $this->logStream();
        $tester = $this->applicationTester($log);

        $exit = $tester->run(['command' => CheckMainCommand::NAME, 'repository' => '.', 'last-seen-head' => str_repeat('a', 40), '--peer-key' => ['private-looking-marker']]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringNotContainsString('private-looking-marker', $this->logged($log));
    }

    public function testAFailedCheckIsStillLogged(): void
    {
        $log = $this->logStream();
        $tester = $this->applicationTester($log);

        $exit = $tester->run(['command' => CheckMainCommand::NAME, 'repository' => './missing', 'last-seen-head' => str_repeat('a', 40)]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('exited with code "1"', $this->logged($log));
    }

    public function testRefusedUsageOfAnotherCommandIsStillLogged(): void
    {
        $log = $this->logStream();
        $tester = $this->applicationTester($log, new Command('intakt:other')->addOption('value', null, InputOption::VALUE_REQUIRED));

        $tester->run(['command' => 'intakt:other', '--value' => null]);

        self::assertStringContainsString('Error thrown while running command "intakt:other', $this->logged($log));
    }

    /**
     * @param resource|null $log
     */
    private function applicationTester($log = null, ?Command $other = null): ApplicationTester
    {
        $dispatcher = new EventDispatcher();
        $listener = new ExitInvalidOnRefusedUsage();
        $dispatcher->addListener(ConsoleEvents::ERROR, $listener);
        $dispatcher->addListener(ConsoleEvents::TERMINATE, $listener->onTerminate(...));
        $dispatcher->addSubscriber(new ErrorListener(new Logger(LogLevel::DEBUG, $log ?? $this->logStream())));
        $application = new Application();
        $application->setDispatcher($dispatcher);
        $application->setAutoExit(false);
        $application->addCommand(new CheckMainCommand(new CheckMain(new HardenedGit(), new Filesystem())));
        if ($other instanceof Command) {
            $application->addCommand($other);
        }

        return new ApplicationTester($application);
    }

    /**
     * @return resource
     */
    private function logStream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * @param resource $log
     */
    private function logged($log): string
    {
        rewind($log);

        return (string) stream_get_contents($log);
    }
}
