<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\StartReviewHandler;
use Intakt\Tests\Core\Git\TemporaryRepository;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartReviewHandler::class)]
#[CoversClass(LocalChangeReview::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(CleanupOnStop::class)]
final class StartReviewHandlerTest extends TestCase
{
    private TemporaryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testStartingAReviewQueuesARoundAndReturnsItsIdWithoutWaitingForTheJudge(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(new LogicException('The start path must not wait for the judge.'));
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-7';
        });

        $roundId = $this->handler($reviews)(new StartReview('alpha', 'sample', 'Add the thing.', new ChangeSource($this->repository->path, 'main')));

        self::assertSame('round-7', $roundId);
        self::assertSame('Add the thing.', $seen?->task);
        self::assertEquals(new ChangeSource($this->repository->path, 'main'), $seen->round->source);
    }

    public function testTheSnapshotTheRoundReadsStaysUntilTheRoundIsDone(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-7';
        });

        $this->handler($reviews)(new StartReview('alpha', 'sample', 'Add the thing.', new ChangeSource($this->repository->path, 'main')));

        assert($seen instanceof ChangeReviewRequest);
        self::assertFileExists($seen->checkoutRoot.'/src/Feature.php');
        $this->removeSnapshot($seen->checkoutRoot);
    }

    public function testARefusedRoundLeavesNoSnapshotBehind(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            throw RoundNotNext::for(2, 1);
        });

        try {
            $this->handler($reviews)(new StartReview('alpha', 'sample', 'Add the thing.', new ChangeSource($this->repository->path, 'main')));
            self::fail('The refused round was swallowed.');
        } catch (RoundNotNext) {
            self::assertDirectoryDoesNotExist((string) $seen?->checkoutRoot);
        }
    }

    private function removeSnapshot(string $snapshot): void
    {
        new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser())->removeSnapshot($snapshot);
    }

    private function handler(ChangeReviews $reviews): StartReviewHandler
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return new StartReviewHandler(new LocalChangeReview($panels, $reviews));
    }
}
