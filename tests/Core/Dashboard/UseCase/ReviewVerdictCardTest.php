<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\ReviewChange\RoundEndReasons;
use Intakt\Core\Review\UseCase\ReviewChange\StoredRoundEndings;
use Intakt\Tests\Support\FixedRoundEndings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(RoundEnding::class)]
#[UsesClass(StoredRoundEndings::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(Cost::class)]
final class ReviewVerdictCardTest extends DashboardPageTestCase
{
    public function testAnApprovedReviewOpensWithAnApprovedVerdictCardAndNoFindingLines(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Approved', $this->headlineOf($crawler));
        self::assertCount(0, $crawler->filter('.verdict-card .vc-findings li'));
    }

    public function testAReviewWithChangesRequestedOpensWithTheCountOfThingsToFixAndOneLinePerActFinding(): void
    {
        $this->givenChangesRequestedWith(['F1' => 'act', 'F2' => 'defer', 'F3' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Changes needed: 2 things to fix', $this->headlineOf($crawler));
        self::assertSame([['P2', 'Text of F1.'], ['P2', 'Text of F3.']], $crawler->filter('.verdict-card .vc-findings li')->each(static fn (Crawler $line): array => [$line->filter('.sev')->text(), $line->filter('.vc-text')->text()]));
    }

    public function testAReviewWithOneActFindingSaysOneThingToFix(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is wrong.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Changes needed: 1 thing to fix', $this->headlineOf($crawler));
    }

    public function testAVerdictCardFindingShowsItsSeverityTitleAndFileAndLine(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is `wrong`.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['P1', 'The total is wrong.', 'src/A.php:7'], [$crawler->filter('.vc-findings .sev')->text(), $crawler->filter('.vc-findings .vc-text')->text(), $crawler->filter('.vc-findings .vc-place')->text()]);
    }

    public function testAVerdictCardFindingWithoutAPlaceShowsNoEmptyPlace(): void
    {
        $this->givenChangesRequestedWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.vc-findings .vc-place'));
    }

    public function testEachVerdictCardFindingLinksToItsFullFindingBelow(): void
    {
        $this->givenChangesRequestedWith(['F1' => 'act', 'F2' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $targets = $crawler->filter('.vc-findings a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['#round-round-1-finding-F1', '#round-round-1-finding-F2'], $targets);
        foreach ($targets as $target) {
            self::assertCount(1, $crawler->filter('section.round .finding-card'.$target));
        }
    }

    public function testAFindingOfAReviewJudgedFromMarksOnlySaysItsTextWasNotRecorded(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->judgeMarksOnly('round-1', ['F1'], []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['?', 'Text not recorded.'], [$crawler->filter('.vc-findings .sev')->text(), $crawler->filter('.vc-findings .vc-text')->text()]);
    }

    public function testARoundThatEndedWithoutAnOutcomeInTimeOpensWithBlockedAndItsReason(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->get(ClockInterface::class)->sleep(3 * 3600 + 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringStartsWith('Blocked: '.RoundEndReasons::NO_OUTCOME_IN_TIME.': the round did not end within 10800 seconds', $this->headlineOf($crawler));
    }

    public function testAJudgedRoundTheQuorumBlockedOpensWithBlockedAndWhereToLook(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->blockReviewer('round-1', 'security', 'Every provider of the tier is out.');
        $this->ledger->judgeFindings('round-1', []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Blocked: the reviewers gave no outcome the quorum policy counts, see each reviewer below', $this->headlineOf($crawler));
    }

    public function testARoundWhoseJudgingFailedOpensWithFailedAndItsReason(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        self::getContainer()->set(RoundEndings::class, new FixedRoundEndings(new RoundEnding(RoundEnds::JudgingFailed, RoundEndReasons::JUDGING_FAILED)));

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Failed: the judging failed', $this->headlineOf($crawler));
    }

    public function testAJudgedRoundWhoseReviewersAllFailedOpensWithFailed(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->failReviewer('round-1', 'correctness', 'The provider is down.');
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->judgeFindings('round-1', []);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Failed: every reviewer failed, see each reviewer below', $this->headlineOf($crawler));
    }

    public function testAReplayedReviewsVerdictCardKeepsTheReplayChip(): void
    {
        $this->flagAsReplay('alpha');
        $this->givenChangesRequestedWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Replay', $crawler->filter('.verdict-card .replay-mark')->text());
    }

    public function testALiveReviewsVerdictCardHasNoReplayChip(): void
    {
        $this->givenChangesRequestedWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.verdict-card .replay-mark'));
    }

    public function testTheVerdictCardShowsTheNewestDecidedRoundWhileANewerRoundIsInReview(): void
    {
        $this->givenChangesRequestedWith(['F1' => 'act']);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Changes needed: 1 thing to fix', 'Round 1'], [$this->headlineOf($crawler), $crawler->filter('.verdict-card .vc-round')->text()]);
    }

    public function testAReviewWithNoDecidedRoundShowsNoVerdictCard(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.verdict-card'));
    }

    public function testTheVerdictCardSitsRightUnderTheTitle(): void
    {
        $this->givenChangesRequestedWith(['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('verdict-card', $crawler->filter('.page-head h1')->nextAll()->first()->attr('class'));
    }

    /**
     * @param array<string, 'act'|'defer'|'reject'> $marks
     */
    private function givenChangesRequestedWith(array $marks): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', count($marks));
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->judgeFindings('round-1', $marks);
    }

    private function headlineOf(Crawler $crawler): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $crawler->filter('.verdict-card .vc-outcome')->text()));
    }
}
