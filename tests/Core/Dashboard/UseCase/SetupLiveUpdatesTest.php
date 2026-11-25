<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\PublishFirstReview;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRuns;
use Intakt\Core\Review\Contract\RoundOpened;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

#[CoversClass(PublishFirstReview::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundOpened::class)]
final class SetupLiveUpdatesTest extends DashboardPageTestCase
{
    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testTheApplicationPublishesTheFirstReviewOnEveryOpenedRound(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('debug:messenger'));

        $tester->execute(['bus' => 'command.bus']);

        self::assertMatchesRegularExpression('#'.preg_quote(RoundOpened::class, '#').'\s+handled by '.preg_quote(PublishFirstReview::class, '#').'\b#', $tester->getDisplay());
    }

    public function testTheFirstRoundOfTheInstallPublishesAPrivateUpdateOnTheShellTopic(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->publisher()(new RoundOpened('round-1', 'alpha'));

        self::assertCount(1, $this->published);
        self::assertSame([DashboardTopics::SHELL], $this->published[0]->getTopics());
        self::assertTrue($this->published[0]->isPrivate());
    }

    public function testThePushedSectionIsTheSectionOfAPageLoadedAfterTheRound(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->publisher()(new RoundOpened('round-1', 'alpha'));
        $page = $this->browser->request('GET', '/setup?step=5');

        $pushed = new Crawler($this->published[0]->getData());
        $target = (string) $pushed->filter('turbo-stream')->attr('target');
        self::assertSame('replace', $pushed->filter('turbo-stream')->attr('action'));
        self::assertCount(1, $page->filter('#'.$target));
        self::assertSame($page->filter('#'.$target)->outerHtml(), $this->normalized($pushed->filter('template')->html()));
        self::assertSame('done', $pushed->filter('template #step-first-review')->attr('data-state'));
        self::assertSame('/reviews/round-1', $pushed->filter('template #step-first-review a.btn')->attr('href'));
    }

    public function testALaterRoundPublishesNothing(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'alpha');

        $this->publisher()(new RoundOpened('round-2', 'alpha'));

        self::assertSame([], $this->published);
    }

    public function testAFailedPublicationIsLoggedAndDoesNotFailTheHandler(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $log = new BufferingLogger();
        $failing = new class implements LiveUpdates {
            public function publish(LiveEvent $event): bool
            {
                throw new RuntimeException('the hub is down');
            }
        };

        $this->publisher($failing, $log)(new RoundOpened('round-1', 'alpha'));

        self::assertSame(['warning'], array_column($log->cleanLogs(), 0));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'setup, last step' => ['/setup?step=5'];
        yield 'setup, check' => ['/setup?step=4'];
        yield 'add project, check' => ['/projects/new?step=3'];
    }

    #[DataProvider('pages')]
    public function testAWizardPageWorksWithoutLiveUpdatesWhenTheHubCannotAuthorizeAListener(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertGreaterThan(0, $crawler->filter('main.content .step')->count());
    }

    // Assembled here from the container's page reading and Twig, because the container inlines its hub, so a recording one cannot be set there.
    private function publisher(?LiveUpdates $updates = null, ?BufferingLogger $log = null): PublishFirstReview
    {
        $container = self::getContainer();
        $pages = $container->get(LedgerReviewPages::class);
        $demo = $container->get(DemoRuns::class);
        $twig = $container->get('twig');
        self::assertInstanceOf(LedgerReviewPages::class, $pages);
        self::assertInstanceOf(DemoRuns::class, $demo);
        self::assertInstanceOf(Environment::class, $twig);
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        });

        return new PublishFirstReview($pages, $demo, $twig, $updates ?? new MercureLiveUpdates($hub, new BufferingLogger()), $log ?? new BufferingLogger());
    }

    private function normalized(string $html): string
    {
        return new Crawler($html)->filter('#step-first-review')->outerHtml();
    }
}
