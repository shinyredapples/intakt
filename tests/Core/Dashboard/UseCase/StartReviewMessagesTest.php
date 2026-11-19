<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\StartReview\StartReviewController;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Mcp\Contract\Tool\OnePanel;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Messenger\DataCollector\MessengerDataCollector;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\VarDumper\Cloner\Data;

// Measured by the profiler on the application's own buses, because the controller test mocks the command bus and so cannot see what the start sends on (#2721, D2).
#[Group('persistence')]
#[CoversClass(StartReviewController::class)]
final class StartReviewMessagesTest extends WebTestCase
{
    private KernelBrowser $browser;

    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        new RoundFixture(self::getContainer());
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
        $container = self::getContainer();
        $container->set(Panels::class, $container->get(OnePanel::class));
    }

    // Each start keeps a snapshot worktree outside the repository until its round is judged, and no round here is judged.
    protected function tearDown(): void
    {
        $forge = new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser());

        foreach (array_slice($this->repository->worktrees(), 1) as $line) {
            $forge->removeSnapshot(substr($line, strlen('worktree ')));
        }

        $this->repository->remove();
        parent::tearDown();
    }

    public function testStartingTheNextRoundDispatchesTheStartAndOneReviewerMessageForThePanelOfOne(): void
    {
        $round = $this->firstRound();
        $token = $this->browser->request('GET', '/reviews/'.$round)->filter('form.start-review input[name=_token]')->attr('value');
        $this->browser->enableProfiler();

        $this->browser->request('POST', '/reviews/'.$round.'/start', ['_token' => $token, 'number' => '2']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame([
            'query.bus '.ReplayMarkOfRound::class,
            'command.bus '.StartReview::class,
            'command.bus '.RunReviewRound::class,
            'command.bus '.RoundOpened::class,
            'command.bus '.ReviewWithReviewer::class,
        ], $this->dispatched());
    }

    private function firstRound(): string
    {
        $commands = self::getContainer()->get('command.bus');
        $start = new StartReview('alpha', RoundFixture::TASK, new RequestedRound('sample', 1, new ChangeSource($this->repository->path, 'main')));
        $round = $commands->dispatch($start)->last(HandledStamp::class)?->getResult();
        assert(is_string($round));

        return $round;
    }

    /**
     * @return list<string>
     */
    private function dispatched(): array
    {
        $profile = $this->browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('messenger');
        self::assertInstanceOf(MessengerDataCollector::class, $collector);

        $dispatched = [];

        foreach ($collector->getMessages() as $dispatch) {
            self::assertInstanceOf(Data::class, $dispatch);
            $dispatched[] = $this->busAndType($dispatch->getValue(true));
        }

        return $dispatched;
    }

    private function busAndType(mixed $dispatch): string
    {
        self::assertIsArray($dispatch);
        self::assertIsString($dispatch['bus']);
        self::assertIsArray($dispatch['message']);
        self::assertIsString($dispatch['message']['type']);

        return $dispatch['bus'].' '.$dispatch['message']['type'];
    }
}
