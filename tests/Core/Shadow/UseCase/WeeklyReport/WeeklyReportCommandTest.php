<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\UseCase\WeeklyReport;

use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\PublishedHead;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Contract\WeeklyReport;
use Intakt\Core\Shadow\Domain\LoopReviewReader;
use Intakt\Core\Shadow\Domain\MergedSpot;
use Intakt\Core\Shadow\Domain\PostedComment;
use Intakt\Core\Shadow\Domain\PublishedComparison;
use Intakt\Core\Shadow\Domain\RecordedPullRequest;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use Intakt\Core\Shadow\Domain\Week;
use Intakt\Core\Shadow\Infrastructure\RecordedFolder;
use Intakt\Core\Shadow\Infrastructure\RecordedMoment;
use Intakt\Core\Shadow\Infrastructure\RecordedPullRequestFile;
use Intakt\Core\Shadow\UseCase\WeeklyReport\WeeklyReportCommand;
use Intakt\Tests\Support\ConsoleDisplay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(WeeklyReportCommand::class)]
#[CoversClass(RecordedPullRequestFile::class)]
#[CoversClass(RecordedMoment::class)]
#[CoversClass(RecordedFolder::class)]
#[UsesClass(ConsoleDisplay::class)]
#[UsesClass(PublishedComparison::class)]
#[UsesClass(RecordedPullRequest::class)]
#[UsesClass(PostedComment::class)]
#[UsesClass(Week::class)]
#[UsesClass(WeeklyReport::class)]
#[UsesClass(PublishedHead::class)]
#[UsesClass(LoopReviewReader::class)]
#[UsesClass(ReviewComparison::class)]
#[UsesClass(MergedSpot::class)]
#[UsesClass(PullRequestComment::class)]
#[UsesClass(LoopReviews::class)]
#[UsesClass(LoopReview::class)]
#[UsesClass(LoopFinding::class)]
#[UsesClass(ReviewerDecision::class)]
final class WeeklyReportCommandTest extends TestCase
{
    private const string RECORDED = __DIR__.'/../../../../Fixtures/Shadow/weekly';
    private const string SUMMARY = '3 heads: 1 agree, 1 disagree, 1 incomplete; findings: 1 matched, 1 loop only, 2 intakt only';

    public function testTheReportPrintsTheWeekOneRowPerHeadReviewedInItAndTheSummary(): void
    {
        $tester = $this->tester('2026-12-01T00:00:00Z');

        $status = $tester->execute(['folder' => self::RECORDED, '--until' => '2026-11-09T00:00:00Z']);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Week from 2026-11-02T00:00:00Z to 2026-11-09T00:00:00Z', $display);
        self::assertStringContainsString('bbb222', $display);
        self::assertStringNotContainsString('bbb000', $display);
        self::assertStringContainsString(self::SUMMARY, $display);
    }

    public function testTheRowsNameEachSidesDecisionsAndTheFindingsOnlyOneSideRaised(): void
    {
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $tester->execute(['folder' => self::RECORDED]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('gpt: changes', $display);
        self::assertStringContainsString('intakt: approve', $display);
        self::assertStringContainsString('gpt: unreadable', $display);
        self::assertStringContainsString('P1 src/Order.php:40', $display);
        self::assertStringContainsString('P2 src/Order.php:90', $display);
        self::assertStringContainsString('P1 src/Pay.php:7', $display);
    }

    public function testWithoutAnEndTheWeekEndsNow(): void
    {
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $tester->execute(['folder' => self::RECORDED]);

        self::assertStringContainsString('Week from 2026-11-02T00:00:00Z to 2026-11-09T00:00:00Z', $tester->getDisplay());
        self::assertStringContainsString(self::SUMMARY, $tester->getDisplay());
    }

    public function testAWeekWithoutReviewedHeadsReportsNone(): void
    {
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $status = $tester->execute(['folder' => self::RECORDED, '--until' => '2026-10-01T00:00:00Z']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('0 heads: 0 agree, 0 disagree, 0 incomplete', $tester->getDisplay());
    }

    public function testAnEndThatIsNoMomentIsAnInputError(): void
    {
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $status = $tester->execute(['folder' => self::RECORDED, '--until' => '2026-02-30T00:00:00Z']);

        self::assertSame(Command::INVALID, $status);
        self::assertThat($tester->getDisplay(), ConsoleDisplay::shows('2026-02-30T00:00:00Z'));
    }

    public function testAFolderThatDoesNotExistIsAnInputError(): void
    {
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $status = $tester->execute(['folder' => self::RECORDED.'/missing']);

        self::assertSame(Command::INVALID, $status);
    }

    #[DataProvider('brokenPullRequests')]
    public function testAnUnreadablePullRequestIsAnInputErrorThatNamesTheFile(string $content): void
    {
        $folder = sys_get_temp_dir().'/weekly-report-'.bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents($folder.'/broken.json', $content);
        $tester = $this->tester('2026-11-09T00:00:00Z');

        try {
            $status = $tester->execute(['folder' => $folder]);
        } finally {
            unlink($folder.'/broken.json');
            rmdir($folder);
        }

        self::assertSame(Command::INVALID, $status);
        self::assertThat($tester->getDisplay(), ConsoleDisplay::shows($folder.'/broken.json'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenPullRequests(): iterable
    {
        $comment = ['author' => 'loop-bot', 'head' => 'h1', 'postedAt' => '2026-11-03T00:00:00Z', 'body' => 'x'];
        $pullRequest = ['pullRequest' => 1, 'loop' => 'loop-bot', 'intakt' => 'intakt-app[bot]', 'comments' => [$comment]];

        yield 'no JSON' => ['not json'];
        yield 'no pull request number' => [self::json([...$pullRequest, 'pullRequest' => '1'])];
        yield 'no loop identity' => [self::json([...$pullRequest, 'loop' => null])];
        yield 'no Intakt identity' => [self::json([...$pullRequest, 'intakt' => 3])];
        yield 'comments that are no list' => [self::json([...$pullRequest, 'comments' => 'none'])];
        yield 'a comment without body' => [self::json([...$pullRequest, 'comments' => [['author' => 'loop-bot', 'head' => 'h1', 'postedAt' => '2026-11-03T00:00:00Z']]])];
        yield 'a comment whose moment has no offset' => [self::json([...$pullRequest, 'comments' => [[...$comment, 'postedAt' => '2026-11-03T00:00:00']]])];
        yield 'a comment whose moment is no date' => [self::json([...$pullRequest, 'comments' => [[...$comment, 'postedAt' => '2026-13-01T00:00:00Z']]])];
        yield 'a comment whose moment holds a null byte' => [self::json([...$pullRequest, 'comments' => [[...$comment, 'postedAt' => "2026-11-03T00:00:00Z\0"]]])];
    }

    public function testAFolderWhoseNameHoldsGlobCharactersStillFindsItsFiles(): void
    {
        $folder = $this->copyOfRecorded('weekly-[*?]-');
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $status = $tester->execute(['folder' => $folder]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(self::SUMMARY, $tester->getDisplay());
    }

    public function testOnlyTheJsonFilesOfTheFolderAreRead(): void
    {
        $folder = $this->copyOfRecorded('weekly-only-json-');
        file_put_contents($folder.'/notes.txt', 'not a pull request');
        mkdir($folder.'/nested.json');
        mkdir($folder.'/nested');
        copy(self::RECORDED.'/41-agree.json', $folder.'/nested/41-agree.json');
        $tester = $this->tester('2026-11-09T00:00:00Z');

        $status = $tester->execute(['folder' => $folder]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(self::SUMMARY, $tester->getDisplay());
    }

    private function copyOfRecorded(string $prefix): string
    {
        $folder = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(4));
        mkdir($folder);
        foreach (glob(self::RECORDED.'/*.json') ?: [] as $file) {
            copy($file, $folder.'/'.basename($file));
        }

        register_shutdown_function(static fn () => new Filesystem()->remove($folder));

        return $folder;
    }

    /**
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function tester(string $now): CommandTester
    {
        $application = new Application();
        $application->addCommand(new WeeklyReportCommand(new PublishedComparison(new LoopReviewReader(), new ReviewComparison()), new MockClock($now)));

        return new CommandTester($application->find('intakt:shadow:weekly-report'));
    }
}
