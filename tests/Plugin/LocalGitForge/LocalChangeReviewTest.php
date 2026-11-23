<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\KeptSnapshots;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(LocalChangeReview::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(CleanupOnStop::class)]
#[UsesClass(KeptSnapshots::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class LocalChangeReviewTest extends TestCase
{
    private TemporaryRepository $repository;
    private BufferingLogger $log;
    private string $snapshot = '';

    // Forces the overlap: RemoveKeptSnapshot removes the snapshot once the round is judged, and the directory is back before this call's own removal, so git fails while it still exists.
    public function testAReviewWhoseSnapshotCannotBeRemovedAfterTheRoundWasJudgedStillReturnsItsVerdict(): void
    {
        $verdict = $this->approved();

        $review = $this->reviewWhereTheJudgeRemovesTheSnapshotFirst(static fn (): ChangeReview => $verdict)->of($this->input());

        self::assertSame($verdict, $review);
        self::assertSame(['warning'], array_column($this->log->cleanLogs(), 0));
    }

    public function testAFailedReviewKeepsItsOwnExceptionWhenTheSnapshotCannotBeRemoved(): void
    {
        $failure = new RuntimeException('the judge failed');

        try {
            $this->reviewWhereTheJudgeRemovesTheSnapshotFirst(static fn (): ChangeReview => throw $failure)->of($this->input());
            self::fail('The review returned instead of failing.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
            self::assertNotInstanceOf(ForgeReadFailed::class, $caught);
        }

        self::assertSame(['warning'], array_column($this->log->cleanLogs(), 0));
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
        $this->log = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->snapshot && is_dir($this->snapshot)) {
            rmdir($this->snapshot);
        }

        $this->repository->remove();
    }

    private function approved(): ChangeReview
    {
        return new ChangeReview('0192a000-0000-7000-8000-000000000000', 1, Decision::Approve, [], [], RoundTokens::of(1, new TokenFigures(100, 0, 0, 0, 20)), Cost::inUsd(0.0001), new BlockReasons());
    }

    /**
     * @param Closure(): ChangeReview $outcome
     */
    private function reviewWhereTheJudgeRemovesTheSnapshotFirst(Closure $outcome): LocalChangeReview
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request) use ($outcome): ChangeReview {
            $this->snapshot = $request->checkoutRoot;
            new KeptSnapshots(new BufferingLogger())->remove('0192a000-0000-7000-8000-000000000000', $this->snapshot);
            mkdir($this->snapshot);

            return $outcome();
        });

        return new LocalChangeReview($this->onePanel(), $reviews, logger: $this->log);
    }

    private function input(): ReviewChangeInput
    {
        $input = new ReviewChangeInput();
        $input->change = 'sample';
        $input->project = 'alpha';
        $input->task = 'Add the thing.';
        $input->repository = $this->repository->path;

        return $input;
    }

    private function onePanel(): Panels
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return $panels;
    }
}
