<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\BranchCleanup\UseCase\CleanUpBranches;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\BranchCleanupSchedule;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\CleanUpBranches;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\CleanUpBranchesCommand;
use Intakt\Core\BranchCleanup\UseCase\CleanUpBranches\CleanUpBranchesHandler;
use Intakt\Core\BranchCleanup\UseCase\RestoreBranch\RestoreBranchCommand;
use Intakt\Tests\Core\BranchCleanup\UseCase\BranchCleanupScenario;
use Intakt\Tests\Support\Log\AssertsLoggedErrors;
use Intakt\Tests\Support\Log\LogRecords;
use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

#[CoversClass(CleanUpBranchesHandler::class)]
#[CoversClass(CleanUpBranches::class)]
#[CoversClass(CleanUpBranchesCommand::class)]
#[CoversClass(RestoreBranchCommand::class)]
#[CoversClass(BranchCleanupSchedule::class)]
#[Group('persistence')]
final class CleanUpBranchesHandlerTest extends KernelTestCase
{
    use AssertsLoggedErrors;

    private BranchCleanupScenario $scenario;

    public function testOneProjectThatFailsDoesNotKeepTheOthersFromTheirWeeklyRun(): void
    {
        $this->scenario->projects = ['broken' => ['repository' => $this->scenario->remote->path.'/missing'], ...$this->scenario->projects];
        $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(61);

        new CleanUpBranchesHandler($this->scenario->forges(), $this->scenario->cleanup(), $this->logger())();

        self::assertLoggedError('error', 'Branch cleanup of broken failed; nothing more of it was deleted this week.');
        self::assertEquals(1, $this->manager()->getConnection()->fetchOne('SELECT COUNT(*) FROM branch_cleanup_warning WHERE project = ?', [BranchCleanupScenario::PROJECT]));
    }

    public function testTheCommandPrintsTheWarningListAndThenTheArchiveRefOfEachDeletedBranch(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(53);
        $warned = $this->command(new CleanUpBranchesCommand($this->scenario->cleanup()), 'intakt:branches:clean-up', ['project' => BranchCleanupScenario::PROJECT]);
        $this->manager()->clear();
        $this->scenario->daysLater(7);

        $deleted = $this->command(new CleanUpBranchesCommand($this->scenario->cleanup()), 'intakt:branches:clean-up', ['project' => BranchCleanupScenario::PROJECT]);

        self::assertStringContainsString(sprintf('warned intakt/7-old %s deletable from ', $head), $warned->getDisplay());
        self::assertStringContainsString('1 warned, 0 deleted, 0 stale, 0 not deleted', $warned->getDisplay());
        self::assertMatchesRegularExpression(sprintf('#deleted intakt/7-old %s archived as refs/intakt/archive/\d{8}T\d{6}Z/intakt/7-old#', $head), $deleted->getDisplay());
        self::assertSame(0, $deleted->getStatusCode());
    }

    public function testTheRestoreCommandNamesTheArchiveRefItRestoredFrom(): void
    {
        $head = $this->scenario->intaktBranch('intakt/7-old');
        $this->scenario->daysLater(53);
        $this->scenario->cleanup()->run(BranchCleanupScenario::PROJECT);
        $this->manager()->clear();
        $this->scenario->daysLater(7);
        $this->scenario->cleanup()->run(BranchCleanupScenario::PROJECT);

        $restored = $this->command(new RestoreBranchCommand($this->scenario->restore()), 'intakt:branches:restore', ['project' => BranchCleanupScenario::PROJECT, 'branch' => 'intakt/7-old']);

        self::assertMatchesRegularExpression(sprintf('#restored intakt/7-old at %s from refs/intakt/archive/#', $head), $restored->getDisplay());
        self::assertSame($head, $this->scenario->headOf('intakt/7-old'));
    }

    public function testTheRestoreCommandFailsForABranchNeverArchived(): void
    {
        $this->scenario->cleanup()->run(BranchCleanupScenario::PROJECT);

        $restored = $this->command(new RestoreBranchCommand($this->scenario->restore()), 'intakt:branches:restore', ['project' => BranchCleanupScenario::PROJECT, 'branch' => 'intakt/7-never']);

        self::assertSame(1, $restored->getStatusCode());
        self::assertStringContainsString('"intakt/7-never"', $restored->getDisplay());
    }

    public function testTheScheduleRunsTheCleanupOnceAWeek(): void
    {
        $messages = new BranchCleanupSchedule(new ArrayAdapter())->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
        $trigger = $messages[0]->getTrigger();
        self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
        self::assertSame('2026-11-30 03:00:00', $trigger->getNextRunDate(new DateTimeImmutable('2026-11-23T03:00:00Z'))?->format('Y-m-d H:i:s'));
    }

    protected function setUp(): void
    {
        $manager = $this->manager();
        $tool = new SchemaTool($manager);
        $tool->dropDatabase();
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $this->scenario = new BranchCleanupScenario($manager);
    }

    protected function tearDown(): void
    {
        $this->scenario->remove();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $input
     */
    private function command(object $command, string $name, array $input): CommandTester
    {
        $application = new Application();
        self::assertIsCallable($command);
        $application->addCommand($command);
        $tester = new CommandTester($application->find($name));
        $tester->execute($input);

        return $tester;
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger(LogRecords::ofThisRun() ?? self::fail('No log records.'));
    }

    private function manager(): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
