<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Maintainer;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Maintainer\Domain\FlowReport;
use Intakt\Core\Maintainer\Domain\HandOver;
use Intakt\Core\Maintainer\Domain\OverdueMilestone;
use Intakt\Core\Maintainer\Domain\PriorityShare;
use Intakt\Core\Maintainer\UseCase\ShowFlowReport\ShowFlowReportCommand;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\IssueHistories;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\LabelApplication;
use Intakt\Plugin\GitHubForge\Contract\SteeringAccounts;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\CompleteListing;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\GitHubWorkSource;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\IntakeStore;
use Intakt\Plugin\GitHubWorkSource\Infrastructure\PullRequestsOfTasks;
use Intakt\Plugin\WorkSource\Contract\Holder;
use Intakt\Plugin\WorkSource\Contract\OpenTask;
use Intakt\Plugin\WorkSource\Contract\OpenWork;
use Intakt\Plugin\WorkSource\Contract\TaskPriority;
use Intakt\Plugin\WorkSource\Contract\Wait;
use Intakt\Plugin\WorkSource\Contract\WorkSource;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\GitHubWorkSource\RecordedIssueReads;
use Intakt\Tests\Plugin\GitHubWorkSource\RecordedPullRequestBranches;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\ErrorHandler\BufferingLogger;

// Over the real work source on recorded forge answers and a SQLite database, so the figures printed are the ones the stored rows give.
#[CoversClass(ShowFlowReportCommand::class)]
#[CoversClass(GitHubWorkSource::class)]
#[UsesClass(FlowReport::class)]
#[UsesClass(HandOver::class)]
#[UsesClass(OverdueMilestone::class)]
#[UsesClass(PriorityShare::class)]
#[UsesClass(OpenWork::class)]
#[UsesClass(OpenTask::class)]
#[UsesClass(Wait::class)]
#[UsesClass(CompleteListing::class)]
#[UsesClass(PullRequestsOfTasks::class)]
#[UsesClass(IntakeStore::class)]
#[UsesClass(EligibilityLabels::class)]
#[UsesClass(ProjectEligibilityLabel::class)]
#[UsesClass(IntakeRules::class)]
final class ShowFlowReportCommandTest extends KernelTestCase
{
    private IntakeStore $store;
    private RecordedIssueReads $reads;
    private BufferingLogger $logger;

    public function testTheCommandPrintsAllThreeFiguresFromFixtureTasks(): void
    {
        $this->reads->lists([
            Issues::task(1, ['intakt', 'status:waiting-on-you', 'priority:urgent'], ['due' => '2026-11-01T08:00:00Z']),
            Issues::task(2, ['intakt', 'priority:urgent'], ['body' => 'Waits for #1', 'due' => '2026-11-01T08:00:00Z']),
            Issues::task(3, ['intakt'], ['body' => 'Waits for #2']),
            Issues::task(4, ['intakt', 'status:parked'], ['due' => '2027-01-01T08:00:00Z']),
        ]);

        $tester = $this->show('alpha', $this->source());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(<<<'TEXT'
            Flow report of project alpha:
            Hand-overs by what they hold:
              #1 Task 1: waits on a person, holds 2 open tasks
              #4 Task 4: waits on a session, holds 0 open tasks
            Overdue milestones with open work:
              due 2026-11-01: 2 open tasks
            Priority mix of the open tasks:
              urgent: 2 (50%)
              high: 0 (0%)
              normal: 2 (50%)
              low: 0 (0%)
              finding for the session: more than a third of the open tasks are urgent

            TEXT, $tester->getDisplay());
    }

    public function testAnEmptyListingSaysThereIsNothingInEachFigure(): void
    {
        $this->reads->lists([]);

        $display = $this->show('alpha', $this->source())->getDisplay();

        self::assertStringContainsString("  no task waits on a session or a person\n", $display);
        self::assertStringContainsString("Overdue milestones with open work:\n  none\n", $display);
        self::assertStringContainsString("  no open task\n", $display);
    }

    public function testAnEmptyMilestonePastItsDueDateIsPrintedAsOneToClose(): void
    {
        $source = self::createStub(WorkSource::class);
        $source->method('openWork')->willReturn(new OpenWork([], [new DateTimeImmutable('2026-10-31')]));

        $display = $this->show('alpha', $source)->getDisplay();

        self::assertStringContainsString("  2026-10-31: no open task, one to close (the owner decides)\n", $display);
    }

    public function testATitleHoldingControlCharactersIsPrintedWithoutThem(): void
    {
        $source = self::createStub(WorkSource::class);
        $source->method('openWork')->willReturn(new OpenWork([new OpenTask(7, "Fix \e[2J\u{9B}x\u{202E}y", TaskPriority::Normal, null, Holder::Person, [])], []));

        $display = $this->show('alpha', $source)->getDisplay();

        self::assertStringContainsString("  #7 Fix  [2J x y: waits on a person, holds 0 open tasks\n", $display);
    }

    public function testAListingThatCameBackShortFromTheWorkSourceFailsWithoutAReport(): void
    {
         = $this->show('alpha', $this->source());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    public function testAShortListingPrintsNoReportAndFails(): void
    {
        $source = self::createStub(WorkSource::class);
        $source->method('openWork')->willReturn(null);

        $tester = $this->show("alpha\u{9B}", $source);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('came back short, so no report is shown', preg_replace('/\s+/', ' ', $tester->getErrorOutput()) ?? '');
    }

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::rowsIn(self::getContainer());
        $this->store = new IntakeStore($entities);
        $this->reads = new RecordedIssueReads();
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
        parent::tearDown();
    }

    private function show(string $project, WorkSource $source): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ShowFlowReportCommand($source, new MockClock('2026-11-20 10:00:00')));
        $tester = new CommandTester($application->find('intakt:maintainer:flow-report'));
        $tester->execute(['project' => $project], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private function source(): GitHubWorkSource
    {
        $histories = self::createStub(IssueHistories::class);
        $histories->method('of')->willReturnCallback(function (string $project, int $number): IssueHistory {
            $listed = $this->reads->lastListed[$number];

            return new IssueHistory($listed->author, array_map(static fn (string $label): LabelApplication => new LabelApplication($label, 'ana'), [...$listed->labels]), $listed->body, $listed->labels);
        });
        $accounts = self::createStub(SteeringAccounts::class);
        $accounts->method('steers')->willReturn(true);
        $references = new IssueReferences();
        $listing = new CompleteListing($this->reads, $histories, $accounts, $this->store, new EligibilityLabels(new ProjectEligibilityLabel('alpha', 'intakt')), $references, $this->logger);

        return new GitHubWorkSource($listing, $histories, $accounts, $references, $this->store, new PullRequestsOfTasks(new RecordedPullRequestBranches($this->reads), $histories, $accounts, $references), $this->logger);
    }
}
