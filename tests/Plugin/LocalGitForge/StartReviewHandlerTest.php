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
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundSourceMissing;
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
#[CoversClass(StartReview::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(CleanupOnStop::class)]
final class StartReviewHandlerTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testStartingAReviewQueuesARoundAndReturnsItsIdWithoutWaitingForTheJudge(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(new LogicException('The start path must not wait for the judge.'));
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-7';
        });

        $roundId = $this->handler($reviews)($this->startOf(null));

        self::assertSame('round-7', $roundId);
        self::assertSame('Add the thing.', $seen?->task);
        self::assertEquals(new ChangeSource((string) realpath($this->repository->path), 'main'), $seen->round->source);
        $this->removeSnapshot($seen->checkoutRoot);
    }

    public function testTheStartAsksForTheRoundNumberTheCallerExpects(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-3';
        });

        $this->handler($reviews)($this->startOf(3));

        self::assertSame(3, $seen?->round->number);
        $this->removeSnapshot($seen->checkoutRoot);
    }

    public function testASourceGivenRelativeToTheWorkingDirectoryIsRecordedAsAnAbsolutePath(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-1';
        });
        $input = new ReviewChangeInput();
        $input->project = 'alpha';
        $input->task = 'Add the thing.';
        $input->change = 'sample';
        $input->repository = '.';
        $before = (string) getcwd();
        chdir($this->repository->path);

        try {
            $this->review($reviews)->startOf($input);
        } finally {
            chdir($before);
        }

        self::assertSame(realpath($this->repository->path), $seen?->round->source?->repository);
        $this->removeSnapshot($seen->checkoutRoot);
    }

    public function testAStartWithoutASourceIsRefusedBeforeItIsSent(): void
    {
        $this->expectException(RoundSourceMissing::class);

        new StartReview('alpha', 'Add the thing.', new RequestedRound('sample'));
    }

    public function testTheSnapshotTheRoundReadsStaysUntilTheRoundIsDone(): void
    {
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-7';
        });

        $this->handler($reviews)($this->startOf(null));

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
            $this->handler($reviews)($this->startOf(null));
            self::fail('The refused round was swallowed.');
        } catch (RoundNotNext) {
            self::assertDirectoryDoesNotExist((string) $seen?->checkoutRoot);
        }
    }

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

    private function handler(ChangeReviews $reviews): StartReviewHandler
    {
        return new StartReviewHandler($this->review($reviews));
    }

    private function startOf(?int $number): StartReview
    {
        return new StartReview('alpha', 'Add the thing.', new RequestedRound('sample', $number, new ChangeSource($this->repository->path, 'main')));
    }

    private function removeSnapshot(string $snapshot): void
    {
        new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser())->removeSnapshot($snapshot);
    }

    private function review(ChangeReviews $reviews): LocalChangeReview
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return new LocalChangeReview($panels, $reviews);
    }
}
