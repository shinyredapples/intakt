<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\HeadlineRoundUpdates;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\PublishBoardHeadline;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

#[CoversClass(HeadlineRoundUpdates::class)]
#[UsesClass(PublishBoardHeadline::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
final class HeadlineRoundUpdatesTest extends TestCase
{
    private const string ROUND = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    /**
     * @var list<LiveEvent>
     */
    private array $published = [];

    public function testAChangedRoundReachesTheRoundPageAndTheBoardHeadline(): void
    {
        $round = $this->createMock(RoundUpdates::class);
        $round->expects(self::once())->method('roundChanged');

        $this->updates($round)->roundChanged(Uuid::fromString(self::ROUND), new RoundProgress(0, 2, false));

        self::assertCount(1, $this->published);
    }

    public function testAStartedReviewerReachesTheBoardHeadline(): void
    {
        $round = $this->createMock(RoundUpdates::class);
        $round->expects(self::once())->method('reviewerStarted')->with(Uuid::fromString(self::ROUND), 'reviewer-1');

        $this->updates($round)->reviewerStarted(Uuid::fromString(self::ROUND), 'reviewer-1');

        self::assertCount(1, $this->published);
    }

    public function testAToolCallReachesOnlyTheRoundPage(): void
    {
        $round = $this->createMock(RoundUpdates::class);
        $round->expects(self::once())->method('toolCalled')->with(Uuid::fromString(self::ROUND), 'reviewer-1', 'read_file');

        $this->updates($round)->toolCalled(Uuid::fromString(self::ROUND), 'reviewer-1', 'read_file');

        self::assertSame([], $this->published);
    }

    public function testStreamedTextAskingAgainASummaryAndAStopReachOnlyTheRoundPage(): void
    {
        $round = $this->createMock(RoundUpdates::class);
        $round->expects(self::once())->method('textStreamed');
        $round->expects(self::once())->method('reviewerAskedAgain');
        $round->expects(self::once())->method('summaryChanged');
        $round->expects(self::once())->method('reviewerStopped');
        $updates = $this->updates($round);
        $id = Uuid::fromString(self::ROUND);

        $updates->textStreamed($id, 'reviewer-1', 'Reading');
        $updates->reviewerAskedAgain($id, 'reviewer-1', 'try-2');
        $updates->summaryChanged($id);
        $updates->reviewerStopped($id, 'reviewer-1');

        self::assertSame([], $this->published);
    }

    private function updates(RoundUpdates $round): HeadlineRoundUpdates
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents([]));
        $pages = new LedgerReviewPages($ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), self::createStub(RoundCosts::class), new ChangeDecision(), new FailureInWords(), new FixedRoundEndings());
        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../../../templates'));
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => '/'.$route));
        $live = self::createStub(LiveUpdates::class);
        $live->method('publish')->willReturnCallback(function (LiveEvent $event): void {
            $this->published[] = $event;
        });

        return new HeadlineRoundUpdates($round, new PublishBoardHeadline($pages, $twig, $live, new LockFactory(new InMemoryStore()), new NullLogger()));
    }
}
