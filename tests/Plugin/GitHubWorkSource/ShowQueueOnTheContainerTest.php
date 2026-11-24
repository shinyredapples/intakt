<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use Intakt\Plugin\GitHubForge\Contract\IssueHistory;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\HeldIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakePlan;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeRules;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;
use Intakt\Plugin\GitHubWorkSource\Domain\IssueReferences;
use Intakt\Plugin\GitHubWorkSource\Domain\ItemReading;
use Intakt\Plugin\GitHubWorkSource\Domain\ListingRows;
use Intakt\Plugin\GitHubWorkSource\Domain\Priority;
use Intakt\Plugin\GitHubWorkSource\Domain\QueuedIssue;
use Intakt\Plugin\GitHubWorkSource\Domain\QueueOrder;
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
use Intakt\Tests\Support\CompiledContainers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

// The command as an install on SQLite runs it, with the project file read and the recorded issues in GitHub's shape served by the test environment, so the label a project names and the order printed are proven together.
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
#[UsesClass(QueueOrder::class)]
#[UsesClass(StoredIssue::class)]
#[UsesClass(SyncState::class)]
#[UsesClass(TaskBranchName::class)]
#[UsesClass(Vouching::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(IssueHistory::class)]
#[UsesClass(WorkQueue::class)]
#[UsesClass(Task::class)]
#[UsesClass(HeldTask::class)]
final class ShowQueueOnTheContainerTest extends KernelTestCase
{
    private const array VARIABLES = ['INTAKT_CONFIG', 'BUILD_RECORDING'];

    private string $file = '';

    /** @var array<string, mixed> */
    private array $saved = [];

    private ?CompiledContainers $containers = null;

    public static function tearDownAfterClass(): void
    {
        self::compiledContainers()->remove();
        parent::tearDownAfterClass();
    }

    public function testTheRecordedIssuesPrintTheReworkThenTheUrgentTaskThenByPriorityThenTheHeldTasks(): void
    {
        $tester = $this->show('queue_open_issues.json', '', 'alpha');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(<<<'TEXT'
            Pick order of project alpha:
              1. #43 Add the queue page (rework of #50)
              2. #42 Fix the dashboard clock
              3. #44 Retry a failed listing
              4. #48 Tidy the lanes page
              5. #47 Trim the ledger page
              6. #41 Show the build tier per task
            Held tasks of project alpha:
              #45 Document the queue page: waits for #43

            TEXT, $tester->getDisplay());
    }

    public function testAListingReadThatFailsPrintsNoOrderAndFails(): void
    {
        $tester = $this->show('queue_listing_fails.json', '', 'alpha');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('The listing of project alpha came back short', $tester->getErrorOutput());
    }

    public function testWithTheDefaultLabelATaskCarryingOnlyReadyIsNotQueuedAndATaskCarryingIntaktIsPicked(): void
    {
        $tester = $this->show('queue_two_tasks_intakt.json', '', 'alpha');

        self::assertSame("Pick order of project alpha:\n  1. #2 Carries intakt\nHeld tasks of project alpha:\n  no task is held\n", $tester->getDisplay());
    }

    public function testWithAProjectLabelATaskCarryingOnlyReadyIsNotQueuedAndATaskCarryingThatLabelIsPicked(): void
    {
        $tester = $this->show('queue_two_tasks_build.json', ', eligibility_label: build', 'alpha');

        self::assertSame("Pick order of project alpha:\n  1. #2 Carries build\nHeld tasks of project alpha:\n  no task is held\n", $tester->getDisplay());
    }

    public function testAProjectWithoutAWatchSectionPrintsNoOrderAndFails(): void
    {
        $tester = $this->show('queue_two_tasks_intakt.json', '', 'beta');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('The listing of project beta came back short', $tester->getErrorOutput());
    }

    protected function setUp(): void
    {
        // Taken before any boot points the cache elsewhere, so tearing down restores the folder the other kernel tests use.
        $this->containers = self::compiledContainers();
        $this->saved = array_intersect_key($_SERVER, array_flip(self::VARIABLES));
        $this->file = sys_get_temp_dir().'/intakt-queue-show-'.bin2hex(random_bytes(4)).'.yaml';
    }

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $_SERVER = [...$_SERVER, ...$this->saved];

        parent::tearDown();
        $this->containers?->restoreTheSharedCache();
        new Filesystem()->remove($this->file);
    }

    private function show(string $recording, string $watchExtra, string $project): CommandTester
    {
        $contents = "projects:\n    alpha:\n        watch: { repository: repos/alpha, base: main, task: Review the pull request.".$watchExtra." }\n";
        new Filesystem()->dumpFile($this->file, $contents);
        $_SERVER['INTAKT_CONFIG'] = $_ENV['INTAKT_CONFIG'] = $this->file;
        $_SERVER['BUILD_RECORDING'] = $_ENV['BUILD_RECORDING'] = __DIR__.'/Fixtures/'.$recording;
        // Keyed by the content, because the path changes per test while the content is the only build input this class sets.
        $this->containers?->useFor([$contents]);
        $kernel = self::bootKernel();
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());

        $tester = new CommandTester(new Application($kernel)->find('intakt:queue:show'));
        $tester->execute(['project' => $project], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function compiledContainers(): CompiledContainers
    {
        return CompiledContainers::beside(self::createKernel()->getCacheDir(), 'queue-show');
    }
}
