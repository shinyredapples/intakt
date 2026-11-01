<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ForwardedProgress;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\StartedReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\StartReviewTool;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartReviewTool::class)]
#[CoversClass(StartedReview::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(ForwardedProgress::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(CleanupOnStop::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
final class StartReviewToolTest extends TestCase
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

    public function testTheToolReviewsTheNamedBranchAndAnswersWithTheActList(): void
    {
        $seen = null;
        $answer = $this->tool($this->reviewing($seen))($this->gateway(), $this->review());

        self::assertSame('changes', $answer->decision);
        self::assertSame(['F1'], array_column($answer->act, 'id'));
        self::assertSame('Add the thing.', $seen?->task);
        self::assertSame('sample', $seen->round->change);
    }

    public function testARoundTheNextOneIsNotIsAnMcpToolError(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(RoundNotNext::for(2, 1));

        try {
            $this->tool($reviews)($this->gateway(), $this->review(round: 2));
            self::fail('The tool should have refused the round.');
        } catch (ToolCallException $refusal) {
            self::assertStringContainsString('Round 2 was asked for', $refusal->getMessage());
        }
    }

    public function testAnEmptyTaskIsAnMcpToolErrorBeforeAnythingIsRead(): void
    {
        $this->expectException(ToolCallException::class);

        $this->tool(self::createStub(ChangeReviews::class))($this->gateway(), $this->review(task: ''));
    }

    public function testARoundBelowOneIsAnMcpToolError(): void
    {
        $this->expectException(ToolCallException::class);

        $this->tool(self::createStub(ChangeReviews::class))($this->gateway(), $this->review(round: 0));
    }

    private function gateway(): ClientGateway
    {
        return new ClientGateway(self::createStub(SessionInterface::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function review(string $task = 'Add the thing.', ?int $round = null): array
    {
        return ['project' => 'alpha', 'task' => $task, 'change' => 'sample', 'repository' => $this->repository->path, 'round' => $round];
    }

    private function tool(ChangeReviews $reviews): StartReviewTool
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return new StartReviewTool(new LocalChangeReview($panels, $reviews, new NameStatusParser()));
    }

    private function reviewing(?ChangeReviewRequest &$seen): ChangeReviews
    {
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$seen, $finding): ChangeReview {
            $seen = $request;

            return new ChangeReview('0192a000-0000-7000-8000-000000000000', 1, Decision::Changes, [], [$finding], new RoundTokens(1, 10, 2, 12));
        });

        return $reviews;
    }
}
