<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\UseCase\CompareHead;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Shadow\Contract\FindingMatch;
use Intakt\Core\Shadow\Contract\HeadComparison;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use Intakt\Core\Shadow\Infrastructure\RecordedHead;
use Intakt\Core\Shadow\UseCase\CompareHead\CompareHeadCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CompareHeadCommand::class)]
#[CoversClass(RecordedHead::class)]
#[UsesClass(LoopReviewReader::class)]
#[UsesClass(ReviewComparison::class)]
#[UsesClass(HeadComparison::class)]
#[UsesClass(FindingMatch::class)]
#[UsesClass(ReviewerDecision::class)]
#[UsesClass(PullRequestComment::class)]
#[UsesClass(LoopReviews::class)]
#[UsesClass(LoopReview::class)]
#[UsesClass(LoopFinding::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(RoundTokens::class)]
final class CompareHeadCommandTest extends TestCase
{
    public function testTheCommandPrintsTheComparisonOfARecordedHead(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => __DIR__.'/../../../../Fixtures/Shadow/compare-head.json']);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString("loop security: unreadable\n", $display);
        self::assertStringContainsString("intakt round 1 light: failed\n", $display);
        self::assertStringContainsString("matched src/A.php:10 loop P1, intakt P2 (severity differs)\n", $display);
        self::assertStringContainsString("intakt only src/B.php:40 P3\n", $display);
        self::assertStringContainsString('1 matched, 5 loop only, 2 intakt only, 1 severity disagreements, 1 intakt failed reviews, 1 unreadable loop reviews, 1500 intakt tokens in 3 reviews', $display);
    }

    public function testAFileWithoutRecordedShadowRoundsIsAnInputError(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['file' => __DIR__.'/../../../../Fixtures/Shadow/loop-review-comments.json']);

        self::assertSame(Command::INVALID, $status);
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new CompareHeadCommand(new LoopReviewReader(), new ReviewComparison()));

        return new CommandTester($application->find('intakt:shadow:compare-head'));
    }
}
