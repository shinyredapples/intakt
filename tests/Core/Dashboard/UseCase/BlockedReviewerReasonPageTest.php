<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
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
use Intakt\Plugin\Vendor\Contract\NoProviderLeft;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\OutOfProvidersForRole;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\StoredSerializerFactory;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

// Runs a whole round through the console's use case, because the page and the console report must name the same reason for one blocked reviewer (#2758).
#[Group('persistence')]
#[CoversClass(ReviewChange::class)]
#[CoversClass(LedgerRounds::class)]
#[UsesClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ShowReviewController::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(ReviewReport::class)]
#[UsesClass(ReviewChangeCommand::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(NoProviderLeft::class)]
final class BlockedReviewerReasonPageTest extends WebTestCase
{
    private const string BLOCKED_ROLE = 'Look at part 1.';

    private KernelBrowser $browser;

    private RoundFixture $fixture;

    private string $roundId = '';

    /** @var list<string> */
    private array $nowState = [];

    public function testTheReviewCommandNamesTheBlockedReviewerAndItsReason(): void
    {
        $console = $this->reviewedByTheCommand();

        self::assertSame(ReviewExit::Blocked->value, $console->getStatusCode());
        self::assertContains('reason: role-1 reviewer blocked, '.OutOfProvidersForRole::REASON, explode("\n", rtrim($console->getDisplay(), "\n")));
    }

    public function testTheRoundsReviewersTableShowsBlockedWithTheReason(): void
    {
        $this->reviewedByTheCommand();

        $state = $this->browser->request('GET', '/reviews/'.$this->roundId.'/record')->filter('#round-'.$this->roundId.'-reviewer-'.$this->blockedReviewer().'-state');

        self::assertSame(['Blocked', OutOfProvidersForRole::REASON], [$state->filter('.chip')->text(), $state->filter('.muted')->text()]);
    }

    public function testTheNowBoxShowsBlockedWithTheReason(): void
    {
        $this->reviewedByTheCommand();

        self::assertSame(['Blocked', OutOfProvidersForRole::REASON], $this->nowState);
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
        $application->addCommand(new ReviewChangeCommand(new LocalChangeReview($panels, $this->reviewChange()), new ReviewReport(StoredSerializerFactory::create())));
        $tester = new CommandTester($application->find('intakt:review'));

        try {
            $tester->execute(['change' => RoundFixture::CHANGE, '--project' => 'alpha', '--task' => RoundFixture::TASK, '--repository' => $repository->path]);
        } finally {
            $repository->remove();
        }

        return $tester;
    }

    private function blockedReviewer(): string
    {
        foreach ($this->fixture->transactions()->stored($this->roundId)->reviewers() as $reviewer) {
            if ('role-1' === $reviewer->roleName()) {
                return $reviewer->id()->toRfc4122();
            }
        }

        self::fail('The round has no role-1 reviewer.');
    }

    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    private function reviewChange(): ReviewChange
    {
        $fixture = $this->fixture;
        $vendor = new OutOfProvidersForRole(self::BLOCKED_ROLE);
        $delivered = 0;
        $work = function () use ($fixture, $vendor, &$delivered): void {
            foreach (array_slice($fixture->bus->messages, $delivered) as $message) {
                $fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($fixture->judgings() as $judging) {
                // Read before the judge's call, because the Now box shows a round only until it is judged.
                $this->roundId = $judging->roundId;
                $now = $this->browser->request('GET', '/reviews/'.$judging->roundId)->filter('#round-'.$judging->roundId.'-now-reviewer-'.$this->blockedReviewer().'-state');
                $this->nowState = [$now->filter('.chip')->text(), $now->filter('.muted')->text()];
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
}
