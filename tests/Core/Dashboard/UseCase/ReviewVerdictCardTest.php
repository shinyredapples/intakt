<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\GlossaryTerm;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Tests\Support\FixedRoundEndings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(GlossaryTerm::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundEnding::class)]
final class ReviewVerdictCardTest extends DashboardPageTestCase
{
    public function testAnApprovedReviewShowsApprovedInTheCardRightUnderTheTitle(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Approved', $crawler->filter('.page-head h1 + .verdict-card .verdict-words')->text());
        self::assertCount(0, $crawler->filter('.verdict-card .verdict-findings'));
    }

    public function testAReviewWithFindingsToFixSaysHowManyThingsNeedFixing(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act', 'F2' => 'act', 'F3' => 'act', 'F4' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Changes needed: 3 things to fix', $crawler->filter('.verdict-card .verdict-words')->text());
        self::assertSame('To fix?'.GlossaryTerm::ToFix->explanation(), $crawler->filter('.verdict-card .verdict-to-fix .term[data-term="fix"]')->text());
    }

    public function testAJudgedReviewNamesItsReviewPassAndThatItWasCheckedEachWithItsExplanation(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['pass', 'checked'], $crawler->filter('.verdict-card .verdict-pass .term')->each(static fn (Crawler $term): string => (string) $term->attr('data-term')));
        self::assertSame([GlossaryTerm::ReviewPass->explanation(), GlossaryTerm::Checked->explanation()], $crawler->filter('.verdict-card .verdict-pass [role="tooltip"]')->each(static fn (Crawler $tip): string => $tip->text()));
        self::assertStringStartsWith('Review pass 1?', $crawler->filter('.verdict-card .verdict-pass')->text());
        self::assertStringContainsString(GlossaryTerm::ReviewPass->explanation().', Checked?', $crawler->filter('.verdict-card .verdict-pass')->text(null, false));
    }

    public function testAChangesRoundReadsChangesNeededWithTheOutcomesOwnWordsInTheTooltip(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $chip = $this->browser->request('GET', '/reviews/round-1')->filter('.verdict-card .top .chip');

        self::assertSame('Changes needed', $chip->text());
        self::assertSame('Changes requested', $chip->attr('title'));
    }

    public function testOneFindingToFixIsCountedInTheSingular(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Changes needed: 1 thing to fix', $crawler->filter('.verdict-card .verdict-words')->text());
    }

    public function testChangesWithNothingToFixNowNamesNoCountAndListsNothing(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Changes needed', $crawler->filter('.verdict-card .verdict-words')->text());
        self::assertCount(0, $crawler->filter('.verdict-card .verdict-findings'));
    }

    public function testEachFindingToFixIsOneLineWithItsSeverityTextAndPlace(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [['P1', 'The total is off by one.', 'src/A.php:7']],
            $crawler->filter('.verdict-findings li')->each(static fn (Crawler $line): array => [$line->filter('.sev')->text(), $line->filter('.vf-title')->text(), $line->filter('.vf-place')->text()]),
        );
    }

    public function testEachFindingLineLinksToItsFullFindingFurtherDown(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act', 'F2' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $targets = $crawler->filter('.verdict-findings a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['#round-round-1-finding-F1', '#round-round-1-finding-F2'], $targets);
        foreach ($targets as $target) {
            self::assertCount(1, $crawler->filter('li.finding-card'.$target));
        }
    }

    public function testTheLineOfAPlaceIsKeptApartFromItsPathSoACutPathStillShowsIt(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['src/A.php', ':7'], [$crawler->filter('.vf-place .vf-path')->text(), $crawler->filter('.vf-place .vf-line')->text()]);
    }

    public function testAFileWhoseNameEndsInAColonAndDigitsIsShownWholeWithoutAnInventedLine(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixIn('round-1', 'src/report:2026', null);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('src/report:2026', $crawler->filter('.vf-place .vf-path')->text());
        self::assertCount(0, $crawler->filter('.vf-place .vf-line'));
    }

    public function testAPathHoldingAColonKeepsItsRecordedLineApart(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixIn('round-1', 'src/long:folder/<draft>.php', 1234);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['src/long:folder/<draft>.php', ':1234'], [$crawler->filter('.vf-place .vf-path')->text(), $crawler->filter('.vf-place .vf-line')->text()]);
    }

    public function testAnApprovedRoundWhoseJudgeRejectedAFindingListsNothingToFix(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', ['F1' => 'reject']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Approved', $crawler->filter('.verdict-card .verdict-words')->text());
        self::assertCount(0, $crawler->filter('.verdict-card .verdict-findings'));
    }

    public function testABlockedRoundWithoutAnEndingSaysTheReviewersCouldNotGiveAVerdict(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->blockReviewer('round-1', 'correctness', 'The diff is too large.');
        $this->ledger->blockReviewer('round-1', 'security', 'The diff is too large.');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Blocked: the reviewers could not give a verdict on this change', $crawler->filter('.verdict-card .verdict-words')->text());
    }

    public function testAFindingWithoutAPlaceShowsNoPlace(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.verdict-findings .vf-place'));
    }

    public function testAChangesNeededCardIsMarkedAsNeedingYou(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filter('.verdict-card.needs'));
    }

    public function testAnApprovedCardIsNotMarkedAsNeedingYou(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filter('.verdict-card'));
        self::assertCount(0, $crawler->filter('.verdict-card.needs'));
    }

    public function testABlockedReviewSaysBlockedWithTheReasonInWords(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringStartsWith('Blocked: '.RoundEndReasons::NO_OUTCOME_IN_TIME.': the round did not end within 10800 seconds', $crawler->filter('.verdict-card .verdict-words')->text());
        self::assertCount(0, $crawler->filter('.verdict-card.needs'));
    }

    public function testAFailedReviewSaysFailedWithTheReasonInWords(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Failed: the judging failed', $crawler->filter('.verdict-card .verdict-words')->text());
    }

    public function testAReviewWhoseReviewersAllFailedSaysSoInWords(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->failReviewer('round-1', 'correctness', 'The provider is down.');
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Failed: every reviewer failed, so nobody checked this change', $crawler->filter('.verdict-card .verdict-words')->text());
    }

    public function testAReplayedReviewsCardKeepsTheReplayChip(): void
    {
        $this->flagAsReplay('alpha');
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Replay', $crawler->filter('.verdict-card .verdict-replay')->text());
    }

    public function testALiveReviewsCardCarriesNoReplayChip(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.verdict-card .verdict-replay'));
    }

    public function testAReviewWhoseLatestRoundIsStillInReviewShowsNoCard(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(0, $crawler->filter('.verdict-card'));
    }

    public function testAJudgedRoundWithoutARecordedOutcomeSaysSoInsteadOfGuessing(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Checked, but the outcome was not recorded', $crawler->filter('.verdict-card .verdict-words')->text());
    }

    private function givenAnApprovedRound(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');
    }

    /**
     * @param array<string, 'act'|'defer'|'reject'> $marks
     */
    private function givenAChangesRoundWith(array $marks): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeFindings('round-1', $marks);
    }

    private function givenAChangesRound(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
    }
}
