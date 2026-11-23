<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
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
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(FindingUrgency::class)]
final class ReviewVerdictCardTest extends DashboardPageTestCase
{
    public function testAnApprovedReviewShowsApprovedInTheCardRightUnderTheTitle(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Approved', $crawler->filter('.page-head .verdict-card .verdict-words')->text());
        self::assertCount(0, $crawler->filter('.to-fix'));
    }

    public function testAReviewWithFindingsToFixSaysChangesNeededAndHowManyToFix(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act', 'F2' => 'act', 'F3' => 'act', 'F4' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Changes needed', '3 to fix'], [$crawler->filter('.verdict-card .verdict-words')->text(), $crawler->filter('.verdict-card .verdict-count')->text()]);
        self::assertSame('To fix', $crawler->filter('.to-fix h2')->text());
    }

    public function testOneFindingToFixIsCountedAsOneToFix(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('1 to fix', $crawler->filter('.verdict-card .verdict-count')->text());
    }

    public function testChangesWithNothingToFixNowCountsZeroAndListsNothing(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'defer']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Changes needed', '0 to fix'], [$crawler->filter('.verdict-card .verdict-words')->text(), $crawler->filter('.verdict-card .verdict-count')->text()]);
        self::assertCount(0, $crawler->filter('.to-fix'));
    }

    public function testAnApprovedPassCountsZeroToFixAndZeroUrgent(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('0 to fix', $crawler->filter('.verdict-card .verdict-count')->text());
    }

    public function testAPassThatEndedWithoutAVerdictShowsNoCountLine(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([1, 0], [$crawler->filter('.verdict-card')->count(), $crawler->filter('.verdict-card .verdict-count')->count()]);
    }

    public function testEachFindingToFixIsOneCardWithItsUrgencyTitleAndPlace(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [['Urgent', 'The total is off by one.', 'src/A.php:7']],
            $crawler->filter('.to-fix .fix-card')->each(static fn (Crawler $card): array => [$card->filter('.urgency')->text(), $card->filter('.fix-title')->text(), $card->filter('.fix-place')->text()]),
        );
    }

    public function testEachFindingCardOpensTheFindingInTheFullRecord(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act', 'F2' => 'act']);
        $this->browser->disableReboot();

        $targets = $this->browser->request('GET', '/reviews/round-1')->filter('.to-fix a.fix-card')->each(static fn (Crawler $link): string => (string) $link->attr('href'));

        self::assertSame(['/reviews/round-1/record#round-round-1-finding-F1', '/reviews/round-1/record#round-round-1-finding-F2'], $targets);
        $record = $this->browser->request('GET', '/reviews/round-1/record');
        self::assertCount(2, $record->filter('li.finding-card#round-round-1-finding-F1, li.finding-card#round-round-1-finding-F2'));
    }

    public function testAFileWhoseNameEndsInAColonAndDigitsIsShownWholeWithoutAnInventedLine(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixIn('round-1', 'src/report:2026', null);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('src/report:2026', $crawler->filter('.fix-card .fix-place')->text());
    }

    public function testAPathHoldingAColonKeepsItsRecordedLine(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixIn('round-1', 'src/long:folder/<draft>.php', 1234);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('src/long:folder/<draft>.php:1234', $crawler->filter('.fix-card .fix-place')->text());
    }

    public function testAnUrgentFindingIsCountedBesideTheFindingsToFix(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one. It drops the last item.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('1 to fix', $crawler->filter('.verdict-card .verdict-count')->text());
    }

    public function testACardShowsTheHeadlineAsItsTitleAndTheRestOfTheTextAsItsExplanation(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one. It drops the last item.');

        $card = $this->browser->request('GET', '/reviews/round-1')->filter('.fix-card');

        self::assertSame(['The total is off by one.', 'It drops the last item.'], [$card->filter('.fix-title')->text(), $card->filter('.fix-more')->text()]);
    }

    public function testACardOfOneSentenceHasNoExplanationLine(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.fix-card .fix-more'));
    }

    public function testTheBarHasOneSegmentPerFindingToFixAndTheCountsAreInWords(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([1, 1, '1 urgent, 0 should fix, 0 nice to have'], [$crawler->filter('.urgency-bar .seg')->count(), $crawler->filter('.urgency-bar .seg.urgent')->count(), implode(', ', $crawler->filter('.urgency-counts li')->each(static fn (Crawler $li): string => $li->text()))]);
    }

    public function testAnApprovedPassHasAnEmptyBar(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.urgency-bar .seg'));
    }

    public function testEachReviewerIsAMeerkatMarkedWithItsOwnVerdictAndTheyAreSaidToAgreeOrNot(): void
    {
        $this->givenAChangesRoundWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['✗ Changes needed', '✓ Approved'], $crawler->filter('.reviewer-faces .face-mark')->each(static fn (Crawler $mark): string => $mark->text()));
        self::assertSame('The reviewers disagree', $crawler->filter('.verdict-reviewers .agreement')->text());
        self::assertCount(2, $crawler->filter('.reviewer-faces .face img[src*="code_reviewer-light"]'));
    }

    public function testTwoReviewersAskingForChangesReadBothSayChangesNeeded(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Both say changes needed', $crawler->filter('.verdict-reviewers .agreement')->text());
    }

    public function testTheBarGroupsItsSegmentsInTheLegendsOrderWhateverOrderTheJudgeGaveThem(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeRatedFindings('round-1', ['F1' => 'P3', 'F2' => 'P1', 'F3' => 'P2', 'F4' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['seg urgent', 'seg urgent', 'seg should-fix', 'seg nice-to-have'], $crawler->filter('.urgency-bar .seg')->each(static fn (Crawler $seg): string => (string) $seg->attr('class')));
    }

    public function testEachLineOfTheLegendHasTheShapeOfItsUrgency(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeRatedFindings('round-1', ['F1' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['shape urgent', 'shape should-fix', 'shape nice-to-have'], $crawler->filter('.urgency-counts li svg')->each(static fn (Crawler $shape): string => (string) $shape->attr('class')));
        self::assertCount(1, $crawler->filter('.urgency-counts li.urgent svg path'));
    }

    public function testAFindingWithoutAnUrgencyIsCountedInWordsSoTheWordsAddUpToTheCount(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeRatedFindings('round-1', ['F1' => null, 'F2' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['2 to fix', '0 urgent, 1 should fix, 0 nice to have, 1 urgency not recorded', 'seg should-fix, seg unranked'], [$crawler->filter('.verdict-count')->text(), implode(', ', $crawler->filter('.urgency-counts li')->each(static fn (Crawler $li): string => $li->text())), implode(', ', $crawler->filter('.urgency-bar .seg')->each(static fn (Crawler $seg): string => (string) $seg->attr('class')))]);
    }

    public function testEveryFindingHavingAnUrgencyShowsNoLineForAnUnrecordedOne(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeRatedFindings('round-1', ['F1' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.urgency-counts li.unranked, .urgency-bar .seg.unranked'));
    }

    public function testAReviewerOnAModelOfAKnownLabIsLabelledWithTheLab(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->startReviewerOn('round-1', 'correctness', 'moonshotai/Kimi-K3.1:deepinfra');
        $this->ledger->startReviewerOn('round-1', 'security', 'acme/robot-7');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeRound('round-1');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Moonshot AI', 'acme/robot-7'], $crawler->filter('.reviewer-faces .face-lab')->each(static fn (Crawler $lab): string => $lab->text()));
    }

    public function testAFailedReviewerBesideOneAskingForChangesIsNotCountedAsAgreeing(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'majority');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->failReviewer('round-1', 'security', 'timeout');
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['1 of 2 says changes needed', '– Failed'], [$crawler->filter('.verdict-reviewers .agreement')->text(), $crawler->filter('.reviewer-faces .face-mark')->last()->text()]);
    }

    public function testAReviewerWhoseModelWasNotRecordedIsShownWithoutALab(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Model not recorded', 'Model not recorded'], $crawler->filter('.reviewer-faces .face-lab')->each(static fn (Crawler $lab): string => $lab->text()));
    }

    public function testAJudgedPassIsNamedCheckedAboveTheVerdict(): void
    {
        $this->givenAnApprovedRound();

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Pass 1 · Checked', $crawler->filter('.verdict-card .kicker')->text());
    }

    public function testAnApprovedRoundWhoseJudgeRejectedAFindingListsNothingToFix(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', ['F1' => 'reject']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Approved', $crawler->filter('.verdict-card .verdict-words')->text());
        self::assertCount(0, $crawler->filter('.to-fix'));
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

        self::assertCount(0, $crawler->filter('.to-fix .fix-place'));
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
