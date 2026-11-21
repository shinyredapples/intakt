<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\PublishProjectFilter;
use Intakt\Core\Review\Contract\RoundOpened;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

#[CoversClass(PublishProjectFilter::class)]
#[UsesClass(FilterProjects::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundOpened::class)]
final class ProjectFilterLiveUpdatesTest extends DashboardPageTestCase
{
    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testTheApplicationQueuesAnOpenedRoundForTheWorkers(): void
    {
        $commands = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $commands);

        $commands->dispatch(new RoundOpened('round-1', 'alpha'));

        self::assertQueuedMessageCount(1, 'async', RoundOpened::class);
        self::assertEquals([new RoundOpened('round-1', 'alpha')], array_map(static fn (Envelope $queued): object => $queued->getMessage(), self::getQueuedMessages('async')));
    }

    public function testTheApplicationPublishesTheProjectFilterOnEveryOpenedRound(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester(new Application(self::$kernel)->find('debug:messenger'));

        $tester->execute(['bus' => 'command.bus']);

        self::assertMatchesRegularExpression('#'.preg_quote(RoundOpened::class, '#').'\s+handled by '.preg_quote(PublishProjectFilter::class, '#').'\b#', $tester->getDisplay());
    }

    public function testThePublishedFilterIsAPrivateUpdateOnTheShellTopic(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->publisher()(new RoundOpened('round-1', 'alpha'));

        self::assertCount(1, $this->published);
        self::assertSame([DashboardTopics::SHELL], $this->published[0]->getTopics());
        self::assertTrue($this->published[0]->isPrivate());
    }

    public function testThePublishedFilterOffersTheSameOptionsAsTheFilterOfAPageLoadedAfterIt(): void
    {
        $this->ledger->startRound('round-1', 'beta');
        $this->ledger->startRound('round-2', 'alpha');

        $this->publisher()(new RoundOpened('round-2', 'alpha'));
        $page = $this->browser->request('GET', '/projects');

        $pushed = new Crawler($this->published[0]->getData());
        $target = (string) $pushed->filter('turbo-stream')->attr('target');
        $pushedOptions = new Crawler('<select>'.$pushed->filter('template')->html().'</select>')->filter('option')->each($this->option(...));
        self::assertCount(1, $page->filter('select#'.$target));
        self::assertSame($page->filter('select#'.$target.' option')->each($this->option(...)), $pushedOptions);
        self::assertSame([['', 'All projects'], ['alpha', 'alpha'], ['beta', 'beta']], $pushedOptions);
    }

    // Assembled here from the container's filter source and Twig, because the container inlines its hub, so a recording one cannot be set there.
    private function publisher(): PublishProjectFilter
    {
        $container = self::getContainer();
        $projects = $container->get(FilterProjects::class);
        $twig = $container->get('twig');
        self::assertInstanceOf(FilterProjects::class, $projects);
        self::assertInstanceOf(Environment::class, $twig);
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        });

        return new PublishProjectFilter($projects, $twig, new MercureLiveUpdates($hub, new NullLogger()), new LockFactory(new InMemoryStore()), new NullLogger());
    }

    /**
     * @return array{string|null, string}
     */
    private function option(Crawler $option): array
    {
        return [$option->attr('value'), $option->text()];
    }
}
