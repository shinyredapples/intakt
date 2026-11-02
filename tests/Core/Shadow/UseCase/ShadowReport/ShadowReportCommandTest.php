<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\UseCase\ShadowReport;

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
use Intakt\Core\Shadow\Contract\ShadowSummary;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Domain\PeriodSummary;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use Intakt\Core\Shadow\Infrastructure\RecordedHead;
use Intakt\Core\Shadow\UseCase\ShadowReport\ReportFormat;
use Intakt\Core\Shadow\UseCase\ShadowReport\ShadowReportCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShadowReportCommand::class)]
#[UsesClass(ReportFormat::class)]
#[UsesClass(RecordedHead::class)]
#[UsesClass(LoopReviewReader::class)]
#[UsesClass(ReviewComparison::class)]
#[UsesClass(PeriodSummary::class)]
#[UsesClass(ShadowSummary::class)]
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
final class ShadowReportCommandTest extends TestCase
{
    private const string RECORDED = __DIR__.'/../../../../Fixtures/Shadow/report';

    public function testTheReportPrintsOneTableRowPerHeadAndTheSummary(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['folder' => self::RECORDED]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('aaa111', $display);
        self::assertStringContainsString('bbb222', $display);
        self::assertStringContainsString('2 heads, 1 matched, 1 loop only, 1 intakt only, 1 severity disagreements, 1 intakt failed reviews, 1 unreadable loop reviews, 1600 intakt tokens in 4 reviews (400 per review)', $display);
    }

    public function testTheReportAsJsonHoldsTheSummaryAndTheHeads(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['folder' => self::RECORDED, '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $status);
        /** @var array{summary: array<string, mixed>, heads: list<array<string, mixed>>} $report */
        $report = json_decode($tester->getDisplay(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(2, $report['summary']['heads']);
        self::assertSame(1, $report['summary']['matched']);
        self::assertSame(1600, $report['summary']['intaktTokens']);
        self::assertSame(400, $report['summary']['tokensPerReview']);
        self::assertSame(['aaa111', 'bbb222'], array_column($report['heads'], 'head'));
        self::assertSame(1, $report['heads'][0]['severityDisagreements']);
        self::assertSame(1, $report['heads'][1]['intaktFailedReviews']);
    }

    public function testALoopReviewThatCannotBeReadShowsAsUnreadableInTheTableAndTheJson(): void
    {
        $table = $this->tester();
        $table->execute(['folder' => self::RECORDED]);
        $json = $this->tester();
        $json->execute(['folder' => self::RECORDED, '--format' => 'json']);

        self::assertStringContainsString('security: unreadable', $table->getDisplay());
        self::assertStringNotContainsString('security: approve', $table->getDisplay());
        self::assertStringContainsString('"outcome": "unreadable"', $json->getDisplay());
        /** @var array{heads: list<array{loopDecisions: list<array{reviewer: string, outcome: string}>}>} $report */
        $report = json_decode($json->getDisplay(), true, 16, JSON_THROW_ON_ERROR);
        self::assertContains(['reviewer' => 'security', 'outcome' => 'unreadable'], $report['heads'][1]['loopDecisions']);
        self::assertStringContainsString('"unreadableLoopReviews": 1', $json->getDisplay());
    }

    public function testTheReportHoldsNoLimitOrResetTime(): void
    {
        $table = $this->tester();
        $table->execute(['folder' => self::RECORDED]);
        $json = $this->tester();
        $json->execute(['folder' => self::RECORDED, '--format' => 'json']);

        foreach ([$table->getDisplay(), $json->getDisplay()] as $display) {
            self::assertStringNotContainsStringIgnoringCase('limit', $display);
            self::assertStringNotContainsStringIgnoringCase('reset', $display);
        }
    }

    public function testAFolderWithAnUnreadableHeadIsAnInputErrorThatNamesTheFile(): void
    {
        $folder = sys_get_temp_dir().'/shadow-report-'.bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents($folder.'/broken.json', '{"identity": 1}');
        $tester = $this->tester();

        try {
            $status = $tester->execute(['folder' => $folder]);
        } finally {
            unlink($folder.'/broken.json');
            rmdir($folder);
        }

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('broken.json', $tester->getDisplay());
    }

    public function testAFolderThatDoesNotExistIsAnInputError(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['folder' => self::RECORDED.'/missing']);

        self::assertSame(Command::INVALID, $status);
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ShadowReportCommand(new LoopReviewReader(), new ReviewComparison(), new PeriodSummary()));

        return new CommandTester($application->find('intakt:shadow:report'));
    }
}
