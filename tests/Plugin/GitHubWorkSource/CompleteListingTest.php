<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
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
use Intakt\Plugin\WorkSource\Contract\NothingPicked;
use Intakt\Plugin\WorkSource\Contract\Task;
use Intakt\Plugin\WorkSource\Contract\WorkerState;
use Intakt\Plugin\WorkSource\Contract\WorkPick;
use Intakt\Plugin\WorkSource\Contract\WorkQueue;
use Intakt\Tests\Application\EmptyDatabase;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// Because the order must come from every page of one listing, this runs the whole path from GitHub's pages to the pick.
#[Group('persistence')]
#[CoversClass(GitHubIssueReads::class)]
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
#[UsesClass(IssueListing::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(Task::class)]
#[UsesClass(WorkPick::class)]
#[UsesClass(WorkQueue::class)]
#[UsesClass(WorkerState::class)]
#[UsesClass(GitHubMoments::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(BudgetReserve::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
final class CompleteListingTest extends KernelTestCase
{
    private const string NOT_COMPLETE = 'The open issues of the project were not listed completely, so no task is picked.';

    private RecordedIssueApi $api;
    private GitHubWorkSource $source;
    private EntityManagerInterface $entities;

    protected function setUp(): void
    {
        $entities = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entities);
        $this->entities = $entities;
        EmptyDatabase::in(self::getContainer());
        new SchemaTool($entities)->createSchema($entities->getMetadataFactory()->getAllMetadata());
        $this->api = new RecordedIssueApi();
        $this->source = $this->source($this->api);
    }

    public function testTheIssueThatSortsFirstOnTheLastPageIsPicked(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 100), 3 => $this->page(201, 49, ['labels' => [['name' => 'intakt'], ['name' => 'priority:urgent']]])];

        $pick = $this->source->nextWork('alpha', WorkerState::Free);

        self::assertSame(201, $pick->task?->number);
        self::assertCount(3, array_filter($this->api->requests, static fn (string $request): bool => str_contains($request, '/issues')));
    }

    public function testAnAnswerOfThePageCapPicksNothing(): void
    {
        $this->api->pages = array_fill(1, 11, $this->page(1, 100));

        self::assertSame(NothingPicked::ListingIncomplete, $this->source->nextWork('alpha', WorkerState::Free)->nothing);
        self::assertLoggedError('error', self::NOT_COMPLETE);
    }

    public function testALaterPageThatFailsPicksNothingAndLogsTheReason(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '502'];

        self::assertSame(NothingPicked::ListingIncomplete, $this->source->nextWork('alpha', WorkerState::Free)->nothing);
        self::assertLoggedError('error', self::NOT_COMPLETE);
    }

    public function testALaterPageThatIsRateLimitedPicksNothing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '429'];

        self::assertFalse($this->source->queue('alpha')->complete);
        self::assertLoggedError('error', self::NOT_COMPLETE);
    }

    public function testALaterPageThatAnswersAnEmptyObjectPicksNothing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '{}'];

        self::assertFalse($this->source->queue('alpha')->complete);
        self::assertLoggedError('error', self::NOT_COMPLETE);
    }

    public function testAPageOfFullSizeFollowedByAnEmptyOneEndsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '[]'];

        self::assertCount(100, $this->source->queue('alpha')->ready());
    }

    public function testAnIssueWaitedForThatIsOnALaterPageCountsAsOpen(): void
    {
        $this->api->pages = [1 => $this->page(1, 100, ['body' => 'Waits for #150']), 2 => $this->page(150, 1, ['labels' => [['name' => 'bug']]])];

        $queue = $this->source->queue('alpha');

        self::assertCount(100, $queue->held());
        self::assertSame([], $queue->ready());
    }

    public function testAnUnchangedListingIsAnsweredFromTheStoredRowsWithTheFirstPagesValidator(): void
    {
        $this->api->pages = [1 => $this->page(1, 3)];
        self::assertCount(3, $this->source->queue('alpha')->ready());
        $this->api->notModified = true;
        $reads = $this->source($this->api);

        self::assertCount(3, $reads->queue('alpha')->ready());
        self::assertSame(['W/"issues-1"'], $this->api->conditions);
    }

    public function testAListingOfSeveralPagesStoresNoValidator(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 2)];
        $this->source->queue('alpha');

        self::assertNull($this->entities->find(IntakeValidator::class, 'alpha'));
    }

    public function testAnIssueIsReadFromTheRecordedListing(): void
    {
        $this->api->pages = [1 => RecordedIssueApi::fixture('issues_open.json')];
        $reads = $this->api->reads();

        $listing = $reads->listOpen('alpha', null);

        [$task, $pull] = $listing->issues();
        self::assertSame([12, 'Add the export', ['intakt', 'priority:high'], 'ana', false], [$task->number, $task->title, $task->labels, $task->author, $task->isPullRequest]);
        self::assertSame('2026-12-01T08:00:00+00:00', $task->milestoneDue?->format('c'));
        self::assertSame([13, '', true, '2026-11-10T09:30:00+00:00'], [$pull->number, $pull->body, $pull->isPullRequest, $pull->updatedAt->format('c')]);
        self::assertSame('W/"issues-1"', $listing->validator);
    }

    public function testTheLastEditorIsTheAuthorWhenNobodyEditedTheIssue(): void
    {
        self::assertSame('ana', $this->api->reads()->lastEditorOf('alpha', 12));
    }

    public function testTheLastEditorIsTheAccountThatEditedTheIssue(): void
    {
        $this->api->editorAnswer = '{"data":{"repository":{"issue":{"author":{"login":"ana"},"editor":{"login":"mallory"}}}}}';

        self::assertSame('mallory', $this->api->reads()->lastEditorOf('alpha', 12));
    }

    public function testAnIssueTheForgeDoesNotKnowIsNoEditorAnswer(): void
    {
        $this->api->editorAnswer = '{"data":{"repository":{"issue":null}}}';

        $this->expectException(ForgeReadFailed::class);

        $this->api->reads()->lastEditorOf('alpha', 12);
    }

    private function source(RecordedIssueApi $api): GitHubWorkSource
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $labels = new EligibilityLabels([]);
        $snapshots = new IntakeSnapshots($this->entities, new LockFactory(new InMemoryStore()), $api->reads(), new GrantedAccounts(), new IssueReferences(), $labels);

        return new GitHubWorkSource($snapshots, $labels, new GrantedAccounts(), $logger);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function page(int $first, int $count, array $overrides = []): string
    {
        $issues = [];
        for ($number = $first; $number < $first + $count; ++$number) {
            $issues[] = [...['number' => $number, 'title' => 'Task '.$number, 'body' => '', 'user' => ['login' => 'ana'], 'labels' => [['name' => 'intakt']], 'milestone' => null, 'updated_at' => '2026-11-10T10:00:00Z'], ...$overrides];
        }

        return json_encode($issues, JSON_THROW_ON_ERROR);
    }
}
