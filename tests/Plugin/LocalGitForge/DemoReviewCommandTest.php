<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Tests\Core\Disk\DiskWithRoom;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Mcp\Contract\OwnerClientLine;
use Intakt\Core\Mcp\Contract\OwnerToken;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\SampleChange;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\DemoReviewCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\OutputFormat;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(DemoReviewCommand::class)]
#[CoversClass(OwnerClientLine::class)]
#[UsesClass(OwnerToken::class)]
#[UsesClass(ReviewExit::class)]
#[UsesClass(ReviewReport::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(CleanupOnStop::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(OutputFormat::class)]
#[UsesClass(ReviewOutput::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class DemoReviewCommandTest extends TestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000000';

    private TemporaryRepository $sample;

    /**
     * @return iterable<string, array{Decision, int}>
     */
    public static function decisions(): iterable
    {
        yield 'approve' => [Decision::Approve, 0];
        yield 'changes' => [Decision::Changes, 10];
        yield 'blocked' => [Decision::Blocked, 11];
    }

    public function testTheDemoReviewsTheSampleChangeOfTheSampleProjectWithItsTask(): void
    {
        $requests = [];
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request) use (&$requests): ChangeReview {
            $requests[] = $request;

            return $this->outcome(Decision::Approve);
        });

        $this->whenTheDemoRuns($reviews);

        self::assertCount(1, $requests);
        self::assertSame([SampleChange::PROJECT, SampleChange::TASK, SampleChange::CHANGE], [$requests[0]->project, $requests[0]->task, $requests[0]->round->change]);
    }

    public function testTheDemoPrintsTheVerdictAndTheActListFirst(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Changes));

        $report = $this->report()->asLines($this->outcome(Decision::Changes));
        self::assertSame($report, array_slice($this->lines($tester), 0, count($report)));
        self::assertStringStartsWith('changes (', $report[0]);
        self::assertContains('F1 P1 src/Shipping.php:1: Missing check.', $report);
    }

    public function testTheDemoPrintsTheDashboardAddressOfTheReviewedRoundAfterTheReport(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve));

        self::assertSame('Open the review: http://localhost:7781/reviews/'.self::ROUND, $this->lines($tester)[count($this->lines($tester)) - 2]);
    }

    public function testTheDemoPrintsTheMcpLineWithTheOwnerTokenLast(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve), ownerToken: 'demo<token>');

        self::assertSame(
            'Connect an MCP client: claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header \'Authorization: Bearer demo<token>\'',
            array_last($this->lines($tester)),
        );
    }

    public function testWithoutAnOwnerTokenTheMcpLineConnectsWithoutAHeader(): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve), ownerToken: '');

        self::assertStringStartsWith('No MCP owner token found: set MCP_OWNER_TOKEN', (string) array_last($this->lines($tester)));
    }

    #[DataProvider('decisions')]
    public function testTheExitCodeIsTheReviewCommandsForTheDecision(Decision $decision, int $code): void
    {
        $tester = $this->whenTheDemoRuns($this->reviewing($decision));

        self::assertSame($code, $tester->getStatusCode());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function missingRepositories(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('missingRepositories')]
    public function testWithoutASampleRepositoryTheDemoFailsNamingTheVariable(?string $repository): void
    {
        $tester = $this->whenTheDemoRunsOn($repository);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('INTAKT_SAMPLE_REPOSITORY is not set', $tester->getDisplay());
        self::assertStringNotContainsString('claude mcp add', $tester->getDisplay());
    }

    public function testAReportableFailureFailsWithItsMessageAndNoAddress(): void
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new PanelNotConfigured(SampleChange::PROJECT));

        $tester = $this->whenTheDemoRuns($this->reviewing(Decision::Approve), panels: $panels);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"sample"', $tester->getDisplay());
        self::assertStringNotContainsString('Open the review', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->sample = new TemporaryRepository();
        $this->sample->commitFile('README.md', "base\n");
        $this->sample->checkoutNewBranch(SampleChange::CHANGE);
        $this->sample->commitFile('src/Shipping.php', "<?php\n");
        $this->sample->checkout(SampleChange::BASE);
    }

    protected function tearDown(): void
    {
        $this->sample->remove();
    }

    private function reviewing(Decision $decision): ChangeReviews
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturn($this->outcome($decision));

        return $reviews;
    }

    private function outcome(Decision $decision): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/Shipping.php', 1, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $verdicts = [new PanelVerdict(Tier::Strong, new Verdict($decision, [$finding], [], []))];

        return new ChangeReview(self::ROUND, 1, $decision, $verdicts, [$finding], RoundTokens::of(1, new TokenFigures(100, 0, 0, 0, 20)), Cost::inUsd(0.00014));
    }

    private function whenTheDemoRuns(ChangeReviews $reviews, string $ownerToken = 'demo-token', ?Panels $panels = null): CommandTester
    {
        return $this->whenTheDemoIsRun($this->demo($this->sample->path, new LocalChangeReview($panels ?? $this->onePanel(), $reviews, new DiskWithRoom()), $ownerToken));
    }

    private function whenTheDemoRunsOn(?string $repository): CommandTester
    {
        return $this->whenTheDemoIsRun($this->demo($repository, new LocalChangeReview($this->onePanel(), $this->reviewing(Decision::Approve), new DiskWithRoom()), 'demo-token'));
    }

    private function report(): ReviewReport
    {
        return new ReviewReport(StoredSerializerFactory::create());
    }

    /**
     * @return list<string>
     */
    private function lines(CommandTester $tester): array
    {
        return explode("\n", rtrim($tester->getDisplay(), "\n"));
    }

    private function onePanel(): Panels
    {
        $reviewer = new PanelReviewer(new ReviewerRole('correctness', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return $panels;
    }

    private function demo(?string $repository, LocalChangeReview $review, string $ownerToken): DemoReviewCommand
    {
        $routes = new RouteCollection();
        $routes->add(DashboardRoutes::SHOW_REVIEW, new Route('/reviews/{round}'));

        // The base URL with a trailing slash, as an owner may set it, so the line proves it is trimmed.
        return new DemoReviewCommand($review, $this->report(), new UrlGenerator($routes, new RequestContext()), $repository, 7781, 'http://localhost:7781/', new OwnerToken($ownerToken, null));
    }

    private function whenTheDemoIsRun(DemoReviewCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('intakt:demo'));
        $tester->execute([]);

        return $tester;
    }
}
