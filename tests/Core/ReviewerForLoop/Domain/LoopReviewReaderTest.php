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
        /** @var array{comments: list<array{author: string, head: string, body: string}>} $recorded */
        $recorded = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/Shadow/loop-review-comments.json'), true, 16, JSON_THROW_ON_ERROR);
        $comments = array_map(static fn (array $c): PullRequestComment => new PullRequestComment($c['author'], $c['head'], $c['body']), $recorded['comments']);

        $reviews = new LoopReviewReader()->read('loop-bot', 'abc123', $comments)->reviews;

        self::assertSame(['correctness', 'style', 'security', 'docs', 'tests', 're-review'], array_column($reviews, 'reviewer'));
        self::assertSame([LoopVerdict::Changes, LoopVerdict::Approve, LoopVerdict::Unreadable, LoopVerdict::Changes, LoopVerdict::Changes, LoopVerdict::Approve], array_column($reviews, 'verdict'));
        self::assertCount(2, $reviews[0]->findings);
        self::assertCount(1, $reviews[3]->findings);
        self::assertEquals([new LoopFinding('src/C.php', 42, Severity::P2)], $reviews[4]->findings);
        self::assertCount(2, $reviews[5]->findings);
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

    public function testTheReviewerIsNamedByTheReviewerLineOutsideQuotesAndFences(): void
    {
        $reader = new LoopReviewReader();

        self::assertSame('gpt', $reader->reviewerNamedIn(new PullRequestComment('loop-bot', 'abc123', "> Reviewer: claude\nReviewer: gpt\n{}")));
        self::assertSame('unknown', $reader->reviewerNamedIn(new PullRequestComment('loop-bot', 'abc123', "```\nReviewer: gpt\n```\nMerged.")));
    }

    public function testACommentHoldsAVerdictOnlyWithAJsonLineOrJsonFenceEvenABrokenOne(): void
    {
        $reader = new LoopReviewReader();

        self::assertTrue($reader->holdsVerdict(new PullRequestComment('loop-bot', 'abc123', "```json\n{\"verdict\":\n```")));
        self::assertTrue($reader->holdsVerdict(new PullRequestComment('loop-bot', 'abc123', '{"verdict": "approve"}')));
        self::assertFalse($reader->holdsVerdict(new PullRequestComment('loop-bot', 'abc123', "Merged this pull request.\n> {\"verdict\": \"approve\"}")));
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

    public function testANumberedFindingIsRead(): void
    {
        $reviews = $this->read("Reviewer: a\n1. [P2] `src/A.php:42`: wrong\n```json\n{\"verdict\": \"changes\"}\n```");

        self::assertEquals([new LoopFinding('src/A.php', 42, Severity::P2)], $reviews->reviews[0]->findings);
    }

    public function testATaggedLineInsideALongerFenceIsNotAFinding(): void
    {
        $reviews = $this->read("Reviewer: a\n````php\n[P1] `src/Example.php:12` example\n```\n[P1] `src/B.php:3` still fenced\n````\n{\"verdict\":\"changes\"}");

        self::assertSame([], $reviews->reviews[0]->findings);
        self::assertSame(LoopVerdict::Changes, $reviews->reviews[0]->verdict);
    }

    public function testAFenceWithAnInfoStringHidesItsFindingsAndKeepsTheLaterVerdict(): void
    {
        $reviews = $this->read("Reviewer: a\n```php title=x\n[P1] src/F.php:1 in fence\n```\n```json\n{\"verdict\": \"approve\"}\n```");

        self::assertSame([], $reviews->reviews[0]->findings);
        self::assertSame(LoopVerdict::Approve, $reviews->reviews[0]->verdict);
    }

    public function testAJsonVerdictFenceWithCrlfLineEndingsIsRead(): void
    {
        self::assertSame(LoopVerdict::Changes, $this->only("Reviewer: a\r\n```json\r\n{\"verdict\":\r\n \"changes\"}\r\n```\r\n"));
    }

    public function testAFinalFencedJsonThatIsNoObjectIsUnreadable(): void
    {
        self::assertSame(LoopVerdict::Unreadable, $this->only("Reviewer: a\n{\"verdict\":\"approve\"}\n```json\n[\"changes\"]\n```"));
    }

    #[DataProvider('blockingSeverities')]
    public function testAnApprovalListingAnOpenP1OrP2FindingCountsAsChanges(string $severity): void
    {
        self::assertSame(LoopVerdict::Changes, $this->only("Reviewer: a\n- [{$severity}] src/A.php:1 bad\n```json\n{\"verdict\": \"approve\"}\n```"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockingSeverities(): iterable
    {
        yield 'P1' => ['P1'];
        yield 'P2' => ['P2'];
    }

    public function testAnApprovalListingOnlyAP3FindingStaysApproval(): void
    {
        self::assertSame(LoopVerdict::Approve, $this->only("Reviewer: a\n- [P3] src/A.php:1 nit\n```json\n{\"verdict\": \"approve\"}\n```"));
    }

    public function testAnApprovalWithAP2FiledUnderFollowUpsStaysApproval(): void
    {
        $body = "Reviewer: a\nResolved: 1.\n\nFollow-ups:\n- [P2] src/A.php:3: left as follow-up\n- [P3] src/B.php:4: nit\n\n```json\n{\"verdict\": \"approve\", \"summary\": \"ok\"}\n```";

        $review = $this->read($body)->reviews[0];

        self::assertSame(LoopVerdict::Approve, $review->verdict);
        self::assertEquals([new LoopFinding('src/A.php', 3, Severity::P2), new LoopFinding('src/B.php', 4, Severity::P3)], $review->findings);
    }

    public function testAP1FiledUnderFollowUpsStillTurnsAnApprovalIntoChanges(): void
    {
        self::assertSame(LoopVerdict::Changes, $this->only("Reviewer: a\nFollow-ups:\n- [P1] src/A.php:12: wrong\n```json\n{\"verdict\":\"approve\"}\n```"));
    }

    public function testAP2BeforeTheFollowUpsLineStillTurnsAnApprovalIntoChanges(): void
    {
        self::assertSame(LoopVerdict::Changes, $this->only("Reviewer: a\n- [P2] src/A.php:12: open\nFollow-ups:\n- [P3] src/B.php:1: nit\n```json\n{\"verdict\":\"approve\"}\n```"));
    }

    #[DataProvider('hiddenFollowUpsLines')]
    public function testAFencedOrQuotedFollowUpsLineDoesNotFileTheFindingsAfterIt(string $followUps): void
    {
        self::assertSame(LoopVerdict::Changes, $this->only("Reviewer: a\n{$followUps}\n- [P2] src/A.php:12: open\n```json\n{\"verdict\":\"approve\"}\n```"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hiddenFollowUpsLines(): iterable
    {
        yield 'quoted' => ['> Follow-ups:'];
        yield 'fenced' => ["```\nFollow-ups:\n```"];
        yield 'json fence' => ["```json\nFollow-ups:\n```"];
    }

    public function testAnUnreadableVerdictStaysUnreadableDespiteAP1Finding(): void
    {
        self::assertSame(LoopVerdict::Unreadable, $this->only("Reviewer: a\n- [P1] src/A.php:1 bad"));
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
