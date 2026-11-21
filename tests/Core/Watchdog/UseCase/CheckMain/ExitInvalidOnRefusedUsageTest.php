<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainCommand;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMainInput;
use Intakt\Core\Watchdog\UseCase\CheckMain\ExitInvalidOnRefusedUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ExitInvalidOnRefusedUsage::class)]
#[UsesClass(CheckMainCommand::class)]
#[UsesClass(CheckMain::class)]
#[UsesClass(CheckMainInput::class)]
#[UsesClass(HardenedGit::class)]
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

    public function testAnErrorThatIsNotRefusedUsageKeepsItsExitCode(): void
    {
        $event = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new RuntimeException('failed'), new Command(CheckMainCommand::NAME));

        new ExitInvalidOnRefusedUsage()($event);

        self::assertSame(1, $event->getExitCode());
    }

    private function applicationTester(): ApplicationTester
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleEvents::ERROR, new ExitInvalidOnRefusedUsage());
        $application = new Application();
        $application->setDispatcher($dispatcher);
        $application->setAutoExit(false);
        $application->addCommand(new CheckMainCommand(new CheckMain(new HardenedGit(), new Filesystem())));

        return new ApplicationTester($application);
    }
}
