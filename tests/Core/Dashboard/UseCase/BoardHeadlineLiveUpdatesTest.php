<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Infrastructure\HeadlineRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\PublishBoardHeadline;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

#[CoversClass(HeadlineRoundUpdates::class)]
#[UsesClass(PublishBoardHeadline::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
final class BoardHeadlineLiveUpdatesTest extends DashboardPageTestCase
{
    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testTheApplicationSendsEveryRoundUpdateThroughTheHeadline(): void
    {
        self::assertInstanceOf(HeadlineRoundUpdates::class, self::getContainer()->get(RoundUpdates::class));
    }

    public function testAStartedReviewerPushesTheHeadlineThatNamesItsChange(): void
    {
        $round = Uuid::v7();
        $this->ledger->startRoundOf('42', $round->toRfc4122(), 1);
        $this->ledger->startReviewer($round->toRfc4122(), 'correctness');

        $this->updates()->roundChanged($round, new RoundProgress(0, 2, false));

        self::assertCount(1, $this->published);
        self::assertSame([DashboardTopics::SHELL], $this->published[0]->getTopics());
        self::assertStringContainsString('The Code reviewers are reviewing change 42 of', $this->published[0]->getData());
    }

    // Assembled here from the container's board and Twig, because the container inlines its hub, so a recording one cannot be set there.
    private function updates(): HeadlineRoundUpdates
    {
        $container = self::getContainer();
        $pages = $container->get(LedgerReviewPages::class);
        $twig = $container->get('twig');
        self::assertInstanceOf(LedgerReviewPages::class, $pages);
        self::assertInstanceOf(Environment::class, $twig);
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        });

        return new HeadlineRoundUpdates(self::createStub(RoundUpdates::class), new PublishBoardHeadline($pages, $twig, new MercureLiveUpdates($hub, new NullLogger()), new LockFactory(new InMemoryStore()), new NullLogger()));
    }
}
