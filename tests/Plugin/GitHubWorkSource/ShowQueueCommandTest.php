<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\StoredIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\SyncState;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskBranchName;
use Intakt\Plugin\GitHubWorkSource\Domain\Vouching;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\CompleteListing;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\PullRequestsOfTasks;
use Intakt\Plugin\GitHubWorkSource\UseCase\ShowQueue\ShowQueueCommand;
use Intakt\Plugin\WorkSource\Contract\HeldTask;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;

// The command over the real work source on recorded forge answers, so the order it prints is the one the rules compute.
#[CoversClass(ShowQueueCommand::class)]
#[UsesClass(GitHubWorkSource::class)]
#[UsesClass(CompleteListing::class)]
#[UsesClass(PullRequestsOfTasks::class)]
#[UsesClass(IntakeStore::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectNamesNoEligibilityLabel::class)]
#[UsesClass(IntakeRules::class)]
#[UsesClass(IntakePlan::class)]
#[UsesClass(IntakeValidator::class)]
#[UsesClass(HeldIssue::class)]
#[UsesClass(IssueReferences::class)]
#[UsesClass(ItemReading::class)]
#[UsesClass(ListingRows::class)]
#[UsesClass(Priority::class)]
#[UsesClass(QueuedIssue::class)]
#[UsesClass(StoredIssue::class)]
#[UsesClass(SyncState::class)]
#[UsesClass(TaskBranchName::class)]
#[UsesClass(Vouching::class)]
#[UsesClass(WorkQueue::class)]
#[UsesClass(Task::class)]
#[UsesClass(HeldTask::class)]
final class ShowQueueCommandTest extends KernelTestCase
{
    private IntakeStore $store;
    private RecordedIssueReads $reads;
    private BufferingLogger $logger;
    private string $eligibilityLabel = 'intakt';

    public function testThePickOrderPutsTheUrgentTaskFirstThenTheReworkThenPriorityThenDueDate(): void
    {
        $this->reads->lists([
            Issues::task(1, ['intakt', 'priority:low']),
            Issues::task(2, ['intakt', 'priority:high'], ['due' => '2026-12-01T08:00:00Z']),
            Issues::task(3, ['intakt'], ['due' => '2026-12-05T08:00:00Z']),
            Issues::task(4),
            Issues::task(5, ['intakt', 'priority:urgent']),
            Issues::task(6),
            Issues::pullRequest(20, 'Closes #6'),
        ]);

        $tester = $this->show('alpha');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(<<<'TEXT'
            Pick order of project alpha:
              1. #5 Task 5
              2. #6 Task 6 (rework of #20)
              3. #2 Task 2
              4. #3 Task 3
              5. #4 Task 4
              6. #1 Task 1
            Held tasks of project alpha:
              no task is held

            TEXT, $tester->getDisplay());
    }

    public function testTheHeldTasksFollowThePickOrderEachWithItsReason(): void
    {
        $this->reads->lists([Issues::task(3), Issues::task(10, ['intakt'], ['body' => 'Waits for #3']), Issues::task(11, ['intakt'], ['body' => 'Waits for #10'])]);

        $display = $this->show('alpha')->getDisplay();

        self::assertSame(<<<'TEXT'
            Pick order of project alpha:
              1. #3 Task 3
            Held tasks of project alpha:
              #10 Task 10: waits for #3
              #11 Task 11: waits for #10

            TEXT, $display);
    }

    public function testATaskCarryingOnlyReadyIsNeitherInThePickOrderNorAmongTheHeldTasks(): void
    {
        $this->reads->lists([Issues::task(7, ['ready']), Issues::task(8)]);

        $display = $this->show('alpha')->getDisplay();

        self::assertStringContainsString("  1. #8 Task 8\n", $display);
        self::assertStringNotContainsString('#7', $display);
    }

    public function testATaskCarryingTheLabelTheProjectNamesIsInThePickOrder(): void
    {
        $this->eligibilityLabel = 'build';
        $this->reads->lists([Issues::task(7, ['ready']), Issues::task(8, ['intakt']), Issues::task(9, ['build'])]);

        $display = $this->show('alpha')->getDisplay();

        self::assertSame("Pick order of project alpha:\n  1. #9 Task 9\nHeld tasks of project alpha:\n  no task is held\n", $display);
    }

    public function testAQueueWithoutTasksSaysThatNoTaskIsReadyOrHeld(): void
    {
        $this->reads->lists([Issues::task(7, ['ready'])]);

        $display = $this->show('alpha')->getDisplay();

        self::assertSame("Pick order of project alpha:\n  no task is ready\nHeld tasks of project alpha:\n  no task is held\n", $display);
    }

    public function testAShortListingPrintsNoOrderFailsAndLogsTheReason(): void
    {
        $tester = $this->show('alpha');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('The listing of project alpha came back short, so no order is shown; the log says why.', preg_replace('/\s+/', ' ', $tester->getErrorOutput()) ?? '');
        self::assertSame(['GitHub answered 502 to a read.'], $this->reasonsLogged());
    }

    public function testAProjectWithoutAWatchSectionGetsNoOrderAndFails(): void
    {
        $tester = $this->show('beta');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertSame(0, $this->reads->listings);
        self::assertCount(1, $this->reasonsLogged());
    }

    public function testIssueTextNeitherRestylesNorRewritesTheTerminal(): void
    {
        $task = Issues::task(4);
        $this->reads->lists([new ListedIssue(4, "<error>Fix</error>\e[2J now", $task->body, $task->labels, $task->author, $task->updatedAt, null, false)]);

        $display = $this->show('alpha')->getDisplay();

        self::assertStringContainsString("  1. #4 <error>Fix</error> [2J now\n", $display);
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->store = new IntakeStore($entities);
        $this->reads = new RecordedIssueReads();
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
        parent::tearDown();
    }

    private function show(string $project): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ShowQueueCommand($this->source()));
        $tester = new CommandTester($application->find('intakt:queue:show'));
        $tester->execute(['project' => $project], ['capture_stderr_separately' => true]);

        return $tester;
    }

    // Every account a writer and every history as listed, so only the labels, the waits and the pull requests steer the order.
    private function source(): GitHubWorkSource
    {
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willReturnCallback(function (string $project, int $number): IssueHistory {
            $listed = $this->reads->lastListed[$number];

            return new IssueHistory($listed->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), $listed->labels), $listed->body, $listed->labels);
        });
        $accounts = self::createStub(SteeringAccounts::class);
        $accounts->method('steers')->willReturn(true);
        $references = new IssueReferences();
        $listing = new CompleteListing($this->reads, $histories, $accounts, $this->store, new EligibilityLabels(['alpha' => ['eligibility_label' => $this->eligibilityLabel]]), $references, $this->logger);

        return new GitHubWorkSource($listing, $histories, $accounts, $references, $this->store, new PullRequestsOfTasks(new RecordedPullRequestBranches($this->reads), $histories, $accounts, $references), $this->logger);
    }

    /**
     * @return list<mixed>
     */
    private function reasonsLogged(): array
    {
        return array_values(array_map(static fn (mixed $record): mixed => is_array($record) && is_array($record[2] ?? null) ? $record[2]['reason'] ?? null : null, $this->logger->cleanLogs()));
    }
}
