<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\EligibilityLabels;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeSnapshots;
use Intakt\Plugin\GitHubWorkSource\UseCase\ShowQueue\ShowQueueCommand;
use Intakt\Plugin\WorkSource\Contract\HeldTask;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[Group('persistence')]
#[CoversClass(ShowQueueCommand::class)]
#[UsesClass(GitHubWorkSource::class)]
#[UsesClass(IntakeSnapshots::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(IntakeValidator::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(Priority::class)]
#[UsesClass(Task::class)]
#[UsesClass(HeldTask::class)]
#[UsesClass(WorkQueue::class)]
final class ShowQueueCommandTest extends KernelTestCase
{
    public function testTheQueueIsPrintedInPickOrderWithTheHeldTasksAndTheirReasons(): void
    {
        $tester = $this->show([
            Issues::task(1, ['intakt', 'priority:low']),
            Issues::task(2, ['intakt', 'priority:high'], with: ['due' => '2026-12-01T00:00:00Z']),
            Issues::task(3, ['intakt', 'priority:high'], with: ['due' => '2026-11-20T00:00:00Z']),
            Issues::task(4, ['intakt', 'priority:urgent']),
            Issues::task(5, ['intakt'], with: ['body' => 'Waits for #6']),
            Issues::task(6, ['bug']),
            Issues::task(7, ['intakt', 'idea']),
            Issues::task(8, ['intakt'], with: ['author' => 'mallory']),
            Issues::pullRequest(50, 'Closes #1'),
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            "1. #4 Task 4\n2. #1 Task 1\n3. #3 Task 3\n4. #2 Task 2\nheld #5 Task 5 (waits for #6)\n",
            $tester->getDisplay(),
        );
    }

    public function testAnIncompleteListingShowsNoOrderAndFails(): void
    {
        $reads = new RecordedIssueReads();
        $reads->fails();

        $tester = $this->tester($reads);
        $tester->execute(['project' => 'alpha']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('were not listed completely', $tester->getDisplay());
        self::assertLoggedError('error', 'The open issues of the project were not listed completely, so no task is picked.');
    }

    /**
     * @param list<ListedIssue> $issues
     */
    private function show(array $issues): CommandTester
    {
        $reads = new RecordedIssueReads();
        $reads->editors[8] = 'mallory';
        $reads->lists($issues);
        $tester = $this->tester($reads);
        $tester->execute(['project' => 'alpha']);

        return $tester;
    }

    private function tester(RecordedIssueReads $reads): CommandTester
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $accounts = new GrantedAccounts();
        $labels = new EligibilityLabels([]);
        $snapshots = new IntakeSnapshots($entities, new LockFactory(new InMemoryStore()), $reads, $accounts, new IssueReferences(), $labels);

        $application = new Application();
        $application->addCommand(new ShowQueueCommand(new GitHubWorkSource($snapshots, $labels, $accounts, $logger)));

        return new CommandTester($application->find('intakt:queue:show'));
    }
}
