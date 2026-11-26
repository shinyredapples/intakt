<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IdentityAtHead;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\StartReview;
use Intakt\Core\Review\Contract\StartReviewAtHead;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\IdentityAtHeadHandler;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\StartReviewAtHeadHandler;
use Intakt\Tests\Core\Disk\DiskWithRoom;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartReviewAtHeadHandler::class)]
#[CoversClass(IdentityAtHeadHandler::class)]
#[CoversClass(StartReviewAtHead::class)]
#[CoversClass(IdentityAtHead::class)]
#[CoversClass(HeadIdentity::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(CleanupOnStop::class)]
#[UsesClass(StartReview::class)]
#[UsesClass(QueuedRound::class)]
final class StartReviewAtHeadHandlerTest extends TestCase
{
    private TemporaryRepository $repository;
    private string $checked;

    public function testTheRoundReviewsTheHeadTheCallerCheckedEvenWhenTheBranchHasMovedPastIt(): void
    {
        $this->repository->commitFile('src/Unchecked.php', "<?php\n");
        $seen = null;
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen): string {
            $seen = $request;

            return 'round-1';
        });

        new StartReviewAtHeadHandler($this->review($reviews))($this->startAt($this->checked));

        assert($seen instanceof ChangeReviewRequest);
        self::assertSame($this->checked, $seen->identity->commits->head->hash);
        self::assertFileDoesNotExist($seen->checkoutRoot.'/src/Unchecked.php');
        $this->removeSnapshot($seen->checkoutRoot);
    }

    public function testTheStartAnswersTheQueuedRoundWithTheSnapshotItKept(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturn('round-1');

        $queued = new StartReviewAtHeadHandler($this->review($reviews))($this->startAt($this->checked));

        self::assertSame('round-1', $queued->roundId);
        self::assertFileExists($queued->checkoutRoot.'/src/Feature.php');
        $this->removeSnapshot($queued->checkoutRoot);
    }

    public function testARoundOnAHeadThatIsNotInTheRepositoryIsNotStarted(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('start')->willReturn('round-1');

        $this->expectException(ForgeReadFailed::class);

        new StartReviewAtHeadHandler($this->review($reviews))($this->startAt(str_repeat('a', 40)));
    }

    public function testTheIdentityAtANewHeadIsReadWithTheMoveSinceTheReviewedHead(): void
    {
        $next = $this->repository->commitFile('src/Feature.php', "<?php\nreturn 1;\n");

        $answer = new IdentityAtHeadHandler($this->review(self::createStub(ChangeReviews::class)))(new IdentityAtHead($this->startAt($next), new CommitId($this->checked)));

        self::assertSame($next, $answer->identity->commits->head->hash);
        self::assertSame(HeadMoveKind::Commits, $answer->move->kind);
        self::assertSame(['src/Feature.php'], [...$answer->move->paths]);
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('7');
        $this->checked = $this->repository->commitFile('src/Feature.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function review(ChangeReviews $reviews): LocalChangeReview
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return new LocalChangeReview($panels, $reviews, new DiskWithRoom());
    }

    private function startAt(string $head): StartReviewAtHead
    {
        return new StartReviewAtHead(new StartReview('alpha', 'Add the thing.', new RequestedRound('7', null, new ChangeSource($this->repository->path, 'main'))), new CommitId($head));
    }

    private function removeSnapshot(string $snapshot): void
    {
        new LocalGitForge(LocalRepository::existingAt($this->repository->path, 'main'), new NameStatusParser())->removeSnapshot($snapshot);
    }
}
