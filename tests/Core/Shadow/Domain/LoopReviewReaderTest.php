<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoopReviewReader::class)]
#[CoversClass(LoopReview::class)]
#[CoversClass(LoopReviews::class)]
#[CoversClass(LoopFinding::class)]
#[CoversClass(PullRequestComment::class)]
final class LoopReviewReaderTest extends TestCase
{
    public function testRecordedLoopReviewsGiveTheExpectedVerdictsAndFindings(): void
    {
        $recorded = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/Shadow/loop-review-comments.json'), true, 16, JSON_THROW_ON_ERROR);
        $comments = array_map(static fn (array $c): PullRequestComment => new PullRequestComment($c['author'], $c['head'], $c['body']), $recorded['comments']);

        $reviews = new LoopReviewReader()->read('loop-bot', 'abc123', $comments)->reviews;

        self::assertSame(['correctness', 'style', 'security', 'docs'], array_column($reviews, 'reviewer'));
        self::assertSame([LoopVerdict::Changes, LoopVerdict::Approve, LoopVerdict::Unreadable, LoopVerdict::Changes], array_column($reviews, 'verdict'));
        self::assertCount(2, $reviews[0]->findings);
        self::assertCount(1, $reviews[3]->findings);
    }

    public function testOnlyCommentsByTheLoopIdentityAreRead(): void
    {
        $reviews = $this->read('Reviewer: a', ['author' => 'someone']);

        self::assertSame([], $reviews->reviews);
    }

    public function testCommentsForAnotherHeadAreIgnored(): void
    {
        $reviews = $this->read('Reviewer: a', ['head' => 'other']);

        self::assertSame([], $reviews->reviews);
    }

    public function testAReviewWithoutAFinalJsonVerdictIsUnreadable(): void
    {
        self::assertSame(LoopVerdict::Unreadable, $this->only("Reviewer: a\n\nApproved, all good."));
        self::assertSame(LoopVerdict::Unreadable, $this->only(''));
    }

    public function testAReviewWithMalformedJsonIsUnreadableNeverApproval(): void
    {
        self::assertSame(LoopVerdict::Unreadable, $this->only("Reviewer: a\n\n{\"verdict\": \"approve\""));
    }

    #[DataProvider('unknownVerdicts')]
    public function testAnUnknownVerdictValueIsUnreadable(string $json): void
    {
        self::assertSame(LoopVerdict::Unreadable, $this->only("Reviewer: a\n\n".$json));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownVerdicts(): iterable
    {
        yield 'other word' => ['{"verdict": "lgtm"}'];
        yield 'not a string' => ['{"verdict": true}'];
        yield 'missing key' => ['{"result": "approve"}'];
        yield 'array value' => ['{"verdict": ["approve"]}'];
    }

    public function testATaggedLineInsideAFenceIsNotAFinding(): void
    {
        $reviews = $this->read("Reviewer: a\n```\n[P1] `src/A.php:1` x\n```\n{\"verdict\": \"approve\"}");

        self::assertSame([], $reviews->reviews[0]->findings);
    }

    public function testAQuotedTaggedLineIsNotAFinding(): void
    {
        $reviews = $this->read("Reviewer: a\n> [P1] `src/A.php:1` x\n{\"verdict\": \"approve\"}");

        self::assertSame([], $reviews->reviews[0]->findings);
    }

    public function testAQuotedVerdictIsNotRead(): void
    {
        self::assertSame(LoopVerdict::Unreadable, $this->only("Reviewer: a\n> {\"verdict\": \"approve\"}"));
    }

    public function testTheLastJsonVerdictInACommentWins(): void
    {
        $body = "Reviewer: a\n{\"verdict\": \"approve\"}\n```json\n{\"verdict\": \"changes\"}\n```";

        self::assertSame(LoopVerdict::Changes, $this->only($body));
    }

    public function testAFindingCarriesFileLineAndSeverity(): void
    {
        $reviews = $this->read("Reviewer: a\n[P2] `src/A.php:12` text\n{\"verdict\": \"changes\"}");

        self::assertEquals([new LoopFinding('src/A.php', 12, Severity::P2)], $reviews->reviews[0]->findings);
    }

    private function only(string $body): LoopVerdict
    {
        return $this->read($body)->reviews[0]->verdict;
    }

    /**
     * @param array{author?: string, head?: string} $override
     */
    private function read(string $body, array $override = []): LoopReviews
    {
        $comment = new PullRequestComment($override['author'] ?? 'loop-bot', $override['head'] ?? 'abc123', $body);

        return new LoopReviewReader()->read('loop-bot', 'abc123', [$comment]);
    }
}
