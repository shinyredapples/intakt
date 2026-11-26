<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Tests\Core\Disk\DiskWithRoom;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\AgentVendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

// Runs the real reviewer and the console's use case, because the page and the console report must both read the stored verdict, never the model's own word (#2757).
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(Verdict::class)]
#[UsesClass(ShowReviewController::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(ReviewChange::class)]
#[UsesClass(ReviewReport::class)]
#[UsesClass(ReviewChangeCommand::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(LiveProgress::class)]
final class BlockedWithFindingsPageTest extends WebTestCase
{
    private const string BLOCKING_ROLE = 'Look at part 1.';

    private KernelBrowser $browser;

    private RoundFixture $fixture;

    /** @var list<string> */
    private array $nowStates = [];

    private string $roundId = '';

    public function testAReviewerAnsweringBlockedWithFindingsShowsChangesRequestedOnThePageAndInTheConsole(): void
    {
        $console = $this->reviewedByTheCommand();

        $page = $this->browser->request('GET', '/reviews/'.$this->roundId.'/record');
        $lines = explode("\n", rtrim($console->getDisplay(), "\n"));

        self::assertResponseIsSuccessful();
        self::assertSame([['role-1', 'Changes needed'], ['role-2', 'Approved']], $this->rows($page, 'table.reviewers tbody tr', 'td'));
        self::assertEqualsCanonicalizing(['Changes needed', 'Approved'], $this->nowStates);
        self::assertSame('Outcome: Changes requested, decided by the quorum policy all over all 2 reviewers.', $page->filter('#round-'.$this->roundId.'-outcome')->text());
        self::assertSame(ReviewExit::Changes->value, $console->getStatusCode());
        self::assertSame('changes (round 1 counted)', $lines[0]);
        self::assertContains('standard reviewer: changes, 1 findings counted', $lines);
        self::assertContains('standard reviewer: approve, 0 findings counted', $lines);
    }

    public function testTheLiveLogKeepsTheModelsOwnBlocked(): void
    {
        $this->reviewChange()->review($this->request(), new IgnoredProgress());

        self::assertCount(1, array_filter($this->fixture->updates->seen, static fn (string $line): bool => str_starts_with($line, 'answer ') && str_contains($line, ' blocked')));
    }

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        // The page reads the round's end from the clock, so it must share the fixture's time or the round is long overdue and leaves the Now box.
        self::clockAt('2026-10-30 12:00:00 UTC');
        $this->fixture = new RoundFixture(self::getContainer());
    }

    // Runs intakt:review on a branch of a temporary repository, so the console lines are the command's own output.
    private function reviewedByTheCommand(): CommandTester
    {
        $repository = new TemporaryRepository();
        $repository->commitFile('README.md', "base\n");
        $repository->checkoutNewBranch(RoundFixture::CHANGE);
        $repository->commitFile('src/Thing.php', "<?php\n");
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($this->panel());
        $application = new Application();
        $application->addCommand(new ReviewChangeCommand(new LocalChangeReview($panels, $this->reviewChange(), new DiskWithRoom()), new ReviewReport(StoredSerializerFactory::create())));
        $tester = new CommandTester($application->find('intakt:review'));

        try {
            $tester->execute(['change' => RoundFixture::CHANGE, '--project' => 'alpha', '--task' => RoundFixture::TASK, '--repository' => $repository->path]);
        } finally {
            $repository->remove();
        }

        return $tester;
    }

    private function reviewChange(): ReviewChange
    {
        $fixture = $this->fixture;
        $vendor = $this->scriptedReviewer();
        $delivered = 0;
        $work = function () use ($fixture, $vendor, &$delivered): void {
            foreach (array_slice($fixture->bus->messages, $delivered) as $message) {
                $fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($fixture->judgings() as $judging) {
                // Read before the judge's call, because the Now box shows a round only until it is judged.
                $this->roundId = $judging->roundId;
                $this->nowStates = $this->browser->request('GET', '/reviews/'.$judging->roundId)->filter('[id$="-now-reviewers"] [id*="-now-reviewer-"][id$="-state"]')->each(static fn (Crawler $state): string => $state->text());
                $fixture->judge($vendor)($judging);
            }
        };
        $serializer = self::getContainer()->get('serializer.stored');

        return new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$fixture->startHandlerOn($fixture->bus)]]))]),
            $fixture->transactions(),
            new StoredFindings($fixture->entities, $serializer, $fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($fixture->ledger(), $fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($fixture->clock, $work),
            new FailureInWords(),
            $vendor,
            $fixture->noFailedJudgings(),
        );
    }

    // The first role's reviewer answers blocked with a finding and the second approves, so the quorum all shows that the blocked answer counts as changes.
    private function scriptedReviewer(): AgentVendor
    {
        $blocked = VerdictDocument::fromTheDesign()->with('verdict', 'blocked')->with('resolved', [])->with('follow_ups', [])->withFinding('file', 'src/Thing.php')->json();
        $approve = json_encode(['verdict' => 'approve', 'findings' => [], 'resolved' => [], 'follow_ups' => []], JSON_THROW_ON_ERROR);
        $answer = static fn (Model $model, array|string|object $payload): string => str_contains(json_encode($payload, JSON_THROW_ON_ERROR), self::BLOCKING_ROLE) ? $blocked : $approve;
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress($this->fixture->updates), new ReviewerStopping(new StopRequests($this->fixture->ledger(), $this->fixture->ledgerPayloads())));

        return new AgentVendor(new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs), $platform);
    }

    private function request(): ChangeReviewRequest
    {
        $panel = $this->panel();

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
    }

    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Crawler $page, string $rows, string $cells): array
    {
        $found = $page->filter($rows)->each(static fn (Crawler $row): array => [$row->filter($cells)->eq(0)->text(), $row->filter($cells)->eq(2)->text()]);
        sort($found);

        return $found;
    }
}
