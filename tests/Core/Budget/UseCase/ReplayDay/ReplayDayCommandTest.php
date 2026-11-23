<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget\UseCase\ReplayDay;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Budget\Domain\BudgetGuard;
use Intakt\Core\Budget\Domain\CeilingStartGuard;
use Intakt\Core\Budget\Domain\OutageWatch;
use Intakt\Core\Budget\Domain\UsageReadingsStayedAway;
use Intakt\Core\Budget\Infrastructure\DoctrineHolds;
use Intakt\Core\Budget\Infrastructure\DoctrineMeasuredUse;
use Intakt\Core\Budget\Infrastructure\DoctrineOutageNotices;
use Intakt\Core\Budget\UseCase\ReplayDay\DayReplay;
use Intakt\Core\Budget\UseCase\ReplayDay\ReplayDayCommand;
use Intakt\Core\Budget\UseCase\ReplayDay\RolledBackReplay;
use Intakt\Core\Budget\UseCase\ReplayDay\ScriptedStep;
use Intakt\Core\Budget\UseCase\ReplayDay\ScriptHoldsNoStep;
use Intakt\Core\Budget\UseCase\ReplayDay\ScriptLineNotUnderstood;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\ConsoleDisplay;
use Intakt\Tests\Support\KernelTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

// Through the kernel and the Doctrine stores, because the evidence of the guard is this command on a running install.
#[Group('persistence')]
#[CoversClass(ReplayDayCommand::class)]
#[CoversClass(RolledBackReplay::class)]
#[UsesClass(ConsoleDisplay::class)]
#[UsesClass(OutageWatch::class)]
#[UsesClass(UsageReadingsStayedAway::class)]
#[UsesClass(DayReplay::class)]
#[UsesClass(ScriptedStep::class)]
#[UsesClass(ScriptLineNotUnderstood::class)]
#[UsesClass(ScriptHoldsNoStep::class)]
#[UsesClass(BudgetGuard::class)]
#[UsesClass(CeilingStartGuard::class)]
#[UsesClass(DoctrineHolds::class)]
#[UsesClass(DoctrineMeasuredUse::class)]
#[UsesClass(DoctrineOutageNotices::class)]
final class ReplayDayCommandTest extends KernelTestCase
{
    private CommandTester $command;

    public function testTheScriptedDayPrintsEachDecisionOfTheGuardAndEachOutageNoticeOnce(): void
    {
        $this->command->execute(['script' => __DIR__.'/scripted-day.txt']);

        $this->command->assertCommandIsSuccessful();
        self::assertSame([
            '2026-11-02T08:00 read claude_code.main five-hour 20 2026-11-02T13:00: recorded',
            '2026-11-02T08:00 read claude_code.main weekly 10 2026-11-08T08:00: recorded',
            '2026-11-02T08:00 read claude_code.team five-hour 5 2026-11-02T13:00: recorded',
            '2026-11-02T08:00 read claude_code.team weekly 5 2026-11-08T08:00: recorded',
            '2026-11-02T08:00 start claude_code.main 0: start',
            '2026-11-02T08:01 start claude_code.main 1: start',
            '2026-11-02T09:00 limit claude_code.main 2026-11-02T13:00: recorded',
            '2026-11-02T09:00 start claude_code.main 0: cooling-down',
            '2026-11-02T09:00 start claude_code.team 0: start',
            '2026-11-02T09:00 merge: start',
            '2026-11-02T09:05 limit claude_code.team 2026-11-01T13:00: reset-already-passed',
            '2026-11-02T09:05 limit claude_code.team 2026-11-10T13:00: reset-beyond-window',
            '2026-11-02T09:05 start claude_code.team 0: start',
            '2026-11-02T10:00 hold budget-back-off 2026-11-02T10:20: held',
            '2026-11-02T10:00 start claude_code.team 0: budget-backed-off',
            '2026-11-02T10:00 merge: start',
            '2026-11-02T10:20 hold failure-back-off 2026-11-02T10:30: held',
            '2026-11-02T10:20 start claude_code.team 0: failure-backed-off',
            '2026-11-02T10:20 merge: failure-backed-off',
            '2026-11-02T10:30 start claude_code.team 0: start',
            '2026-11-02T11:00 hold pause 2026-11-02T12:00: held',
            '[warning] No usage of account claude_code.team has been read since 2026-11-02T08:00:00+00:00, so only one call at a time runs on it.',
            '2026-11-02T11:00 start claude_code.team 0: paused',
            '2026-11-02T11:00 merge: start',
            '2026-11-02T11:30 hold drain 2026-11-02T11:45: held',
            '2026-11-02T11:30 start claude_code.team 0: draining',
            '2026-11-02T11:30 merge: draining',
            '2026-11-02T11:45 merge: start',
            '2026-11-02T12:00 start claude_code.team 0: start',
            '2026-11-02T12:00 start claude_code.team 1: no-trusted-reading',
            '2026-11-02T12:10 read claude_code.team five-hour 30 2026-11-02T13:00: recorded',
            '2026-11-02T12:10 read claude_code.team weekly 8 2026-11-08T08:00: recorded',
            '2026-11-02T12:10 start claude_code.team 1: start',
            '[warning] No usage of account claude_code.main has been read since 2026-11-02T08:00:00+00:00, so only one call at a time runs on it.',
            '2026-11-02T13:00 start claude_code.main 0: start',
            '2026-11-02T13:05 read claude_code.main five-hour 91 2026-11-02T18:00: recorded',
            '2026-11-02T13:05 start claude_code.main 0: at-ceiling',
            '[warning] No usage of account claude_code.team has been read since 2026-11-02T12:10:00+00:00, so only one call at a time runs on it.',
            '2026-11-02T15:10 start claude_code.team 0: start',
            '2026-11-02T16:00 start claude_code.team 0: start',
        ], $this->linesOf($this->command->getDisplay()));
    }

    public function testAReplayLeavesNoReadingHoldOrNoticeOnTheInstall(): void
    {
        $this->command->execute(['script' => __DIR__.'/scripted-day.txt']);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        foreach (['budget_window_reading', 'budget_account_cooldown', 'budget_shared_hold', 'budget_outage_notice'] as $table) {
            self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
    }

    public function testAMissingScriptFailsWithoutAReplay(): void
    {
        $this->command->execute(['script' => __DIR__.'/no-such-day.txt']);

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        self::assertThat($this->command->getDisplay(), ConsoleDisplay::shows(__DIR__.'/no-such-day.txt'));
    }

    public function testALineNotUnderstoodStopsTheReplayWithItsRule(): void
    {
        $this->executeScript("2026-11-02T08:00 merge\n2026-11-02T08:05 start claude_code.main many\n2026-11-02T08:10 merge\n");

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        $display = $this->command->getDisplay();
        self::assertStringContainsString('2026-11-02T08:00 merge: start', $display);
        self::assertStringContainsString('whole number', $display);
        self::assertStringNotContainsString('08:10', $display);
    }

    public function testAStepBeforeThePreviousOneStopsTheReplayWithItsRule(): void
    {
        $this->executeScript("2026-11-02T08:00 merge\n2026-11-02T07:59 merge\n");

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        $display = $this->command->getDisplay();
        self::assertStringContainsString('2026-11-02T08:00 merge: start', $display);
        self::assertStringContainsString('in time order', $display);
    }

    public function testTheClockAdvancesToEachStepsMoment(): void
    {
        $this->executeScript("2026-11-02T08:00 limit claude_code.main 2026-11-02T09:00\n2026-11-02T08:59 start claude_code.main 0\n2026-11-02T09:00 start claude_code.main 0\n");

        $this->command->assertCommandIsSuccessful();
        self::assertSame([
            '2026-11-02T08:00 limit claude_code.main 2026-11-02T09:00: recorded',
            '2026-11-02T08:59 start claude_code.main 0: cooling-down',
            '2026-11-02T09:00 start claude_code.main 0: start',
        ], $this->linesOf($this->command->getDisplay()));
    }

    public function testAScriptWithoutAStepIsRefusedWithItsRule(): void
    {
        $this->executeScript("# only a comment\n\n");

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        self::assertStringContainsString('The script holds no step.', $this->command->getDisplay());
    }

    public function testAUsedPercentTooLargeToBeAReadingIsRefused(): void
    {
        $this->executeScript("2026-11-02T08:00 read claude_code.main five-hour 1e999 2026-11-02T13:00\n");

        self::assertSame(Command::FAILURE, $this->command->getStatusCode());
        self::assertStringContainsString('A measured use of INF percent is not a reading.', $this->command->getDisplay());
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheCommand(): void
    {
        $replay = self::getContainer()->get(RolledBackReplay::class);
        self::assertInstanceOf(RolledBackReplay::class, $replay);
        $files = self::createStub(Filesystem::class);
        $files->method('readFile')->willThrowException(new InvalidArgumentException('a bug in reading'));
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $this->expectExceptionObject(new InvalidArgumentException('a bug in reading'));

        new ReplayDayCommand($replay, $files)($io, 'day.txt');
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $this->command = new CommandTester(new Application($kernel)->find('intakt:budget:replay'));
    }

    private function executeScript(string $contents): void
    {
        $script = sys_get_temp_dir().'/intakt-day-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($script, $contents);

        try {
            $this->command->execute(['script' => $script]);
        } finally {
            unlink($script);
        }
    }

    /**
     * @return list<string>
     */
    private function linesOf(string $display): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", $display)), static fn (string $line): bool => '' !== $line));
    }
}
