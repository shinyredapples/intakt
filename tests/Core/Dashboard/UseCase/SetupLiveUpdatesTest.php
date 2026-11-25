<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Dom\Element;
use Dom\HTMLDocument;
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

        self::assertMatchesRegularExpression('#'.preg_quote(RoundOpened::class, '#').'\s+(?:handled by \S+ \([^)]*\)\s+)*handled by '.preg_quote(PublishFirstReview::class, '#').'\b#', $tester->getDisplay());
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

    public function testThePushedStreamTurnsTheFirstReviewOfAStepFivePageOpenedBeforeTheRoundIntoTheRound(): void
    {
        $page = HTMLDocument::createFromString($this->browser->request('GET', '/setup?step=5')->outerHtml(), LIBXML_NOERROR);
        self::assertNotSame('done', $page->getElementById('step-first-review')?->getAttribute('data-state'));
        $this->ledger->startRound('round-1', 'alpha');

        $this->publisher()(new RoundOpened('round-1', 'alpha'));
        $this->applyStream($page, $this->published[0]->getData());

        $section = $page->getElementById('step-first-review');
        self::assertSame('done', $section?->getAttribute('data-state'));
        self::assertSame('/reviews/round-1', $section->querySelector('a.btn')?->getAttribute('href'));
        self::assertSame('Ready to start', trim((string) $page->getElementById('s5h')?->textContent));
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
        foreach ([1, 2, 3, 4, 5] as $step) {
            yield 'setup, step '.$step => ['/setup?step='.$step];
            yield 'add project, step '.$step => ['/projects/new?step='.$step];
        }
    }

    #[DataProvider('pages')]
    public function testAWizardPageWorksWithoutLiveUpdatesWhenTheHubCannotAuthorizeAListener(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertCount(1, $crawler->filter('main.content .step h1'));
        self::assertGreaterThan(0, $crawler->filter('main.content .step-nav a[href]')->count());
    }

    // The held-button note itself is written by the connection controller (#3521) and tested in connection.test.mjs; the page must carry what it acts on.
    #[DataProvider('pages')]
    public function testAWizardPageWithoutLiveUpdatesCarriesTheHooksOfTheHeldButtonRule(string $path): void
    {
        $crawler = $this->browser->request('GET', $path);

        self::assertCount(1, $crawler->filter('.shell[data-controller="connection"]'));
        self::assertCount(1, $crawler->filter('.shell [data-connection-target="line"][hidden]'));
        self::assertCount(0, $crawler->filter('form[method="post"] button[type="submit"][disabled]'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function pagesWithActions(): iterable
    {
        yield 'setup, step 1' => ['/setup?step=1', ['Check the address']];
        yield 'add project, step 1' => ['/projects/new?step=1', ['Check the address']];
        yield 'add project, step 4' => ['/projects/new?step=4', ['Check the share and label']];
    }

    // The selector is read from the connection controller (#3521), so the step's own buttons are the ones the rule holds with its "Connecting to live updates…" note when the connection drops; the steps whose forms depend on the install's state are left out.
    /**
     * @param list<string> $labels
     */
    #[DataProvider('pagesWithActions')]
    public function testTheHeldButtonRuleReachesTheEnabledSubmitButtonsOfAWizardStep(string $path, array $labels): void
    {
        $this->browser->request('GET', $path);
        $page = HTMLDocument::createFromString((string) $this->browser->getResponse()->getContent(), LIBXML_NOERROR);

        $held = array_filter(iterator_to_array($page->querySelectorAll('.shell[data-controller="connection"] '.$this->heldActions())), static fn (Element $button): bool => !$button->hasAttribute('disabled'));

        self::assertSame($labels, array_values(array_map(static fn (Element $button): string => trim((string) $button->textContent), $held)));
    }

    private function heldActions(): string
    {
        $controller = file_get_contents(dirname(__DIR__, 4).'/assets/controllers/connection_controller.js');
        self::assertIsString($controller);
        self::assertSame(1, preg_match("/^const ACTIONS = '(.+)';$/m", $controller, $actions));

        return $actions[1];
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

    // Does what Turbo does with a stream on an open page, so the test sees the page change without loading it again; a target the page lacks fails here instead of being skipped as Turbo would.
    private function applyStream(HTMLDocument $page, string $stream): void
    {
        $element = HTMLDocument::createFromString('<!DOCTYPE html>'.$stream, LIBXML_NOERROR)->querySelector('turbo-stream');
        self::assertNotNull($element);
        $target = $page->getElementById((string) $element->getAttribute('target'));
        self::assertNotNull($target, 'The stream targets an element the page does not have.');
        $content = (string) $element->querySelector('template')?->innerHTML;

        match ($element->getAttribute('action')) {
            'replace' => $target->outerHTML = $content,
            'update' => $target->innerHTML = $content,
            default => self::fail('The stream has an action this test does not apply.'),
        };
    }

    private function normalized(string $html): string
    {
        return new Crawler($html)->filter('#step-first-review')->outerHtml();
    }
}
