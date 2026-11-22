<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Dashboard\UseCase\StartReview\ReviewStarts;
use Intakt\Core\Dashboard\UseCase\StartReview\StartReviewController;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\StartReviewHandler;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Messenger\DataCollector\MessengerDataCollector;
use Symfony\Component\VarDumper\Cloner\Data;

// Measured with the profiler on the container's own buses and handlers, because what a press dispatches is decided by the handlers it reaches; only the project's panel and model mapping are stand-ins, as the test install configures no project.
#[Group('persistence')]
#[CoversClass(StartReviewController::class)]
#[UsesClass(ReviewStarts::class)]
#[UsesClass(StartReviewHandler::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(LocalGitForge::class)]
final class StartReviewMessagesTest extends WebTestCase
{
    private const string COMMANDS = 'command.bus';

    private const string QUERIES = 'query.bus';

    private KernelBrowser $browser;

    private RoundFixture $fixture;

    private TemporaryRepository $repository;

    public function testStartingTheNextRoundFromTheDashboardDispatchesTheStartAndQueuesOneMessagePerReviewer(): void
    {
        $round = $this->fixture->startFromSource(new ChangeSource($this->repository->path, 'main'));

        self::assertSame([
            [self::QUERIES, ReplayMarkOfRound::class],
            [self::COMMANDS, StartReview::class],
            [self::COMMANDS, RunReviewRound::class],
            [self::COMMANDS, RoundOpened::class],
            [self::COMMANDS, ReviewWithReviewer::class],
            [self::COMMANDS, ReviewWithReviewer::class],
        ], $this->messagesOfTheStartOf($round));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch(RoundFixture::CHANGE);
        $this->repository->commitFile('src/Thing.php', "<?php\n");
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        // The token the page hands out must still be valid for the post, so both run on one container.
        $this->browser->disableReboot();
        self::clockAt('2026-10-30 12:00:00 UTC');
        $this->fixture = new RoundFixture(self::getContainer());
        $this->givenTheProjectReviewsWithTwoReviewers();
    }

    protected function tearDown(): void
    {
        $this->removeKeptSnapshots();
        $this->repository->remove();
        parent::tearDown();
    }

    /**
     * @return list<array{string, string}>
     */
    private function messagesOfTheStartOf(string $round): array
    {
        $token = $this->browser->request('GET', '/reviews/'.$round)->filter('form.start-review input[name=_token]')->attr('value');
        // The kernel is not rebooted before the post, so without this the profile would also hold what the page and the fixture dispatched.
        $profiler = self::getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $profiler->reset();
        $this->browser->enableProfiler();

        $this->browser->request('POST', '/reviews/'.$round.'/start', ['_token' => $token, 'number' => '2']);

        self::assertResponseStatusCodeSame(303);
        $profile = $this->browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $messages = $profile->getCollector('messenger');
        self::assertInstanceOf(MessengerDataCollector::class, $messages);

        return array_map($this->busAndClassOf(...), array_values($messages->getMessages()));
    }

    /**
     * @return array{string, string}
     */
    private function busAndClassOf(mixed $message): array
    {
        self::assertInstanceOf(Data::class, $message);
        $bus = $message['bus'];
        $dispatched = $message['message'];
        self::assertIsString($bus);
        self::assertInstanceOf(Data::class, $dispatched);
        $value = $dispatched['value'];
        self::assertInstanceOf(Data::class, $value);

        return [$bus, (string) $value->getType()];
    }

    private function givenTheProjectReviewsWithTwoReviewers(): void
    {
        $model = new ReviewerModel(Tier::Strong, Provider::Anthropic);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([
            new PanelReviewer(new ReviewerRole('correctness', 'Check the logic.'), $model, 10),
            new PanelReviewer(new ReviewerRole('security', 'Check the guards.'), $model, 10),
        ], QuorumPolicy::All));
        $vendor = self::createStub(Vendor::class);
        $vendor->method('mapsAModelTo')->willReturn(true);
        self::getContainer()->set(Panels::class, $panels);
        self::getContainer()->set(Vendor::class, $vendor);
    }

    // The start keeps a snapshot of the head for the queued round, whose judge would remove it; no worker runs here.
    private function removeKeptSnapshots(): void
    {
        $forge = new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser());

        foreach (array_slice($this->repository->worktrees(), 1) as $line) {
            $forge->removeSnapshot(substr($line, strlen('worktree ')));
        }
    }
}
