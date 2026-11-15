<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\PublishBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

#[CoversClass(PublishBoardHeadline::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class PublishBoardHeadlineTest extends TestCase
{
    /**
     * @var list<LiveEvent>
     */
    private array $published = [];

    private BufferingLogger $log;

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    public function testAnOpenedRoundReplacesTheBoardHeadlineOnTheShellTopic(): void
    {
        $this->publisher($this->emptyLedger())->opened(new RoundOpened('r1', 'alpha'));

        self::assertCount(1, $this->published);
        self::assertSame(DashboardTopics::SHELL, $this->published[0]->topic);
        self::assertStringStartsWith('<turbo-stream action="replace" target="board-headline"><template>', $this->published[0]->turboStream);
        self::assertStringContainsString('Nothing is running yet. Start the first review.', $this->published[0]->turboStream);
    }

    public function testAJudgedRoundPublishesTheHeadlineToo(): void
    {
        $this->publisher($this->emptyLedger())->judged(new RoundJudged('r1', 'checkout'));

        self::assertCount(1, $this->published);
    }

    public function testABoardThatCannotBeReadIsLoggedAndTheRoundGoesOn(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willThrowException(new RuntimeException('database is locked'));

        $this->publisher($ledger)->opened(new RoundOpened('r1', 'alpha'));

        self::assertSame([], $this->published);
        self::assertSame(['The board headline was not published.'], array_column($this->log->cleanLogs(), 1));
    }

    public function testTwoWorkersOneAfterTheOtherEachPublishTheHeadline(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $this->publisher($this->emptyLedger(), $locks)->opened(new RoundOpened('r1', 'alpha'));
        $this->publisher($this->emptyLedger(), $locks)->judged(new RoundJudged('r1', 'checkout'));

        self::assertCount(2, $this->published);
        self::assertSame([], $this->log->cleanLogs());
    }

    private function emptyLedger(): Ledger
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents([]));

        return $ledger;
    }

    private function publisher(Ledger $ledger, LockFactory $locks = new LockFactory(new InMemoryStore())): PublishBoardHeadline
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::inUsd(0.5));
        $pages = new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), new FixedRoundEndings());

        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../../../templates'));
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => '/'.$route));
        $updates = new class($this->published) implements LiveUpdates {
            /**
             * @param list<LiveEvent> $published
             */
            public function __construct(private array &$published)
            {
            }

            public function publish(LiveEvent $event): void
            {
                $this->published[] = $event;
            }
        };

        return new PublishBoardHeadline($pages, $twig, $updates, $locks, $this->log);
    }
}
