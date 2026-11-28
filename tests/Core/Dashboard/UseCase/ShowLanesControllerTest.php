<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\ShowLanes\HeldForSpace;
use Intakt\Core\Dashboard\UseCase\ShowLanes\ShowLanesController;
use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Disk\Infrastructure\DiskConfiguration;
use Intakt\Core\Disk\Infrastructure\FlooredDiskSpace;
use Intakt\Core\Git\Contract\GitServices;
use Intakt\Tests\Core\Disk\ControlledFreeSpaceReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowLanesController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Character::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(Lane::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(RefuseForeignHost::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(HeldForSpace::class)]
#[UsesClass(FlooredDiskSpace::class)]
#[UsesClass(DiskHold::class)]
#[UsesClass(NearestFolder::class)]
#[UsesClass(ControlledFreeSpaceReader::class)]
final class ShowLanesControllerTest extends DashboardPageTestCase
{
    public function testTheStatusPageNamesTheSameStatusLineAsTheBoardUnderAPathBackToNow(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $page = $this->browser->request('GET', '/lanes');

        self::assertSame('Status', $page->filter('.page-head h1')->text());
        self::assertSame('Running smoothly · 1 reviewer working', $page->filter('.page-head .lead')->text());
        self::assertSame(['/?board=1'], $page->filter('.crumbs a')->each(static fn (Crawler $link): ?string => $link->attr('href')));
        self::assertSame('board', $page->filter('.rail [aria-current="page"]')->attr('data-k'));
    }

    public function testWithNobodyWorkingTheStatusPageSaysWhatAppearsThere(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        $page = $this->browser->request('GET', '/lanes');

        self::assertSame('No reviewer is working right now: reviewers appear here while they review a change', $page->filter('p.working-none')->text());
        self::assertCount(0, $page->filter('.lane'));
    }

    public function testWithOnlyABuildInFlightTheStatusPageLeadsWithTheBuildAndNeverSaysNobodyIsWorking(): void
    {
        $this->ledger->passBuildStep('alpha', 'task-7', 'task');

        $page = $this->browser->request('GET', '/lanes');

        self::assertStringContainsString('1 build running', $page->filter('.page-head .lead')->text());
        self::assertStringNotContainsString('Nobody is working', $page->filter('main')->text());
    }

    public function testEachRunningReviewerIsALaneWithItsProjectChangeAndRound(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $lanes = $this->browser->request('GET', '/lanes')->filter('.working .lane');

        self::assertCount(1, $lanes);
        self::assertSame('42', $lanes->filter('.what')->text());
        self::assertSame('Round 1', $lanes->filter('.meta span')->last()->text());
        self::assertStringStartsWith('correctness', $lanes->filter('.who')->text());
    }

    public function testEachLaneLinksToTheReviewPageOfTheRoundItIsWorkingOn(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->startRoundOf('43', 'round-2', 3);
        $this->ledger->startReviewer('round-2', 'security');

        $lanes = $this->browser->request('GET', '/lanes')->filter('.working a.lane');

        self::assertSame(
            [['/reviews/round-1', 'Round 1'], ['/reviews/round-2', 'Round 3']],
            $lanes->each(static fn (Crawler $lane): array => [$lane->attr('href'), $lane->filter('.meta span')->last()->text()]),
        );
    }

    public function testEachLaneShowsADecorative24PixelMeerkatInBothThemes(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $images = $this->browser->request('GET', '/lanes')->filter('.working .lane .who img.character');

        self::assertSame(
            [['', '24', '24', 'lazy', null, null], ['', '24', '24', 'lazy', null, null]],
            $images->each(static fn (Crawler $image): array => [$image->attr('alt'), $image->attr('width'), $image->attr('height'), $image->attr('loading'), $image->attr('role'), $image->attr('aria-label')]),
        );
        self::assertSame(1, preg_match('~^\S*/roles/code_reviewer-light-\S+\.webp 1x, \S*/roles/code_reviewer-light-\S+\.webp 2x$~', $images->eq(0)->attr('srcset') ?? ''));
        self::assertSame(1, preg_match('~^\S*/roles/code_reviewer-dark-\S+\.webp 1x, \S*/roles/code_reviewer-dark-\S+\.webp 2x$~', $images->eq(1)->attr('srcset') ?? ''));
    }

    public function testTheMeerkatSitsAfterTheLiveDotAndBeforeTheRole(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $who = $this->browser->request('GET', '/lanes')->filter('.working .lane .who');

        self::assertSame(['span dot ok', 'img character character-light', 'img character character-dark', 'span elapsed'], $who->children()->each(static fn (Crawler $child): string => $child->nodeName().' '.$child->attr('class')));
        self::assertSame('correctness, standard', $who->getNode(0)?->childNodes->item(3)?->textContent);
    }

    public function testTheLanesRoleTierAndTimeReadTheSameBesideTheMeerkat(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');

        $who = $this->browser->request('GET', '/lanes')->filter('.working .lane .who');

        self::assertSame('correctness, standardsince 12:00 UTC', $who->text());
    }

    public function testAStatusPageWithNobodyWorkingShowsNoMeerkat(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->judgeRound('round-1');

        self::assertCount(0, $this->browser->request('GET', '/lanes')->filter('img[src*="/roles/"]'));
    }

    public function testWhileTheDiskIsBelowTheFreeBytesFloorTheStatusPageSaysNewWorkIsPausedAndWhere(): void
    {
        self::getContainer()->get(ControlledFreeSpaceReader::class)->report(DiskConfiguration::DEFAULT_MIN_FREE_BYTES - 1, DiskConfiguration::DEFAULT_MIN_FREE_INODES);

        $banner = $this->browser->request('GET', '/lanes')->filter('main .banner');

        self::assertCount(1, $banner);
        self::assertSame(
            sprintf('New work is paused because the disk is low on free space. The disk of %s has 1.9 GiB free, below the floor of 2 GiB (disk.min_free_bytes). Work already running goes on, and new work starts again by itself once space is back.', NearestFolder::of((string) self::getContainer()->getParameter(GitServices::MIRRORS_DIRECTORY))),
            $banner->filter('p')->text(),
        );
    }

    public function testWithTheDiskAtBothFloorsTheStatusPageShowsNoPause(): void
    {
        self::getContainer()->get(ControlledFreeSpaceReader::class)->report(DiskConfiguration::DEFAULT_MIN_FREE_BYTES, DiskConfiguration::DEFAULT_MIN_FREE_INODES);

        self::assertCount(0, $this->browser->request('GET', '/lanes')->filter('main .banner'));
    }

    public function testAReviewerThatHasAnsweredIsNoLane(): void
    {
        $this->ledger->startRoundOf('42', 'round-1', 1);
        $this->ledger->startReviewer('round-1', 'correctness');
        $this->ledger->answer('round-1', 'correctness', 0);

        $page = $this->browser->request('GET', '/lanes');

        self::assertCount(0, $page->filter('.working .lane'));
    }
}
