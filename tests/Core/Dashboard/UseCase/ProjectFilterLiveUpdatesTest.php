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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
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

    public function testTheApplicationPublishesTheProjectFilterOnEveryOpenedRound(): void
    {
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        $listeners = array_map(static fn (callable $listener): string => is_array($listener) && is_object($listener[0]) ? $listener[0]::class : get_debug_type($listener), $events->getListeners(RoundOpened::class));

        self::assertContains(PublishProjectFilter::class, $listeners);
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

        return new PublishProjectFilter($projects, $twig, new MercureLiveUpdates($hub, new NullLogger()), new NullLogger());
    }

    /**
     * @return array{string|null, string}
     */
    private function option(Crawler $option): array
    {
        return [$option->attr('value'), $option->text()];
    }
}
