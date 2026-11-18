<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Reviewer;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Infrastructure\ChosenReviewer;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Tests\Support\KernelTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Uid\UuidV7;

// The platform is the in-process reviewer's door, so whether it is called tells which of the two reviewed.
#[CoversClass(ChosenReviewer::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(JobReview::class)]
#[UsesClass(CommitId::class)]
final class ChosenReviewerTest extends KernelTestCase
{
    // Removed when the failed clone left it empty, because a folder only the test's user can read stops another user from copying the tree.
    protected function tearDown(): void
    {
        $folder = dirname(__DIR__, 4).'/var/review-checkouts';
        if (is_dir($folder) && [] === array_diff((array) scandir($folder), ['.', '..'])) {
            rmdir($folder);
        }
        parent::tearDown();
    }

    public function testTheInstallsReviewerIsTheOneThatChoosesWhereAReviewRuns(): void
    {
        self::assertInstanceOf(ChosenReviewer::class, $this->reviewer());
    }

    public function testARequestWithAJobIsReviewedInTheJobAndNeverThroughThePlatform(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects(self::never())->method('invoke');

        $this->expectException(ProcessFailedException::class);

        (void) $this->reviewer()->review($this->request(new JobReview(new CommitId(str_repeat('a', 40)), 'correctness')), $platform, new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));
    }

    // Told apart by the review server's toolbox, because only the in-process reviewer opens it and it refuses a conversation outside a round.
    public function testARequestWithoutAJobIsReviewedInTheAppsOwnProcessWithItsReadOnlyTools(): void
    {
        try {
            (void) $this->reviewer()->review($this->request(null), self::createStub(PlatformInterface::class), new ResolvedModel(Provider::Anthropic, 'claude-sonnet-5-5'));
            self::fail('The in-process reviewer did not open the review server.');
        } catch (LogicException $refused) {
            self::assertSame('A review over MCP needs the round whose head is under review.', $refused->getMessage());
        }
    }

    private function reviewer(): Reviewer
    {
        $reviewer = self::getContainer()->get(Reviewer::class);
        self::assertInstanceOf(Reviewer::class, $reviewer);

        return $reviewer;
    }

    // The checkout is no repository, so a job's clone fails before any job runs.
    private function request(?JobReview $job): ReviewRequest
    {
        $brief = new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), []);

        return new ReviewRequest(new Checkout(sys_get_temp_dir()), $brief, new ReviewerConversation('You review for correctness.', new UuidV7()), $job);
    }
}
