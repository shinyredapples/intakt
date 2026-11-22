<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\Character;
use Intakt\Core\Dashboard\Domain\MascotPose;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowReview\ReviewPage;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\RoundProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

#[CoversClass(ShowReviewController::class)]
#[CoversClass(ReviewPage::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(MascotPose::class)]
#[UsesClass(Character::class)]
final class ReviewPageOctopusTest extends DashboardPageTestCase
{
    private const string OCTOPUS = 'img.character[src*="/mascot/octopus-bowing-"]';

    public function testAnApprovedNewestRoundShowsTheBowingOctopusBesideItsOutcome(): void
    {
        $this->givenARoundJudged(1, 0);

        $verdict = $this->browser->request('GET', '/reviews/round-1/record')->filter('#round-round-1-verdict');

        self::assertSame('character-row', $verdict->attr('class'));
        self::assertSame(['character character-light', 'character character-dark'], $verdict->filter(self::OCTOPUS)->each(static fn (Crawler $image): ?string => $image->attr('class')));
        self::assertSame('p', $verdict->filter(self::OCTOPUS)->last()->nextAll()->nodeName());
    }

    public function testTheOctopusIsADecorative72PxImageWithItsDoubleForSharpScreens(): void
    {
        $this->givenARoundJudged(1, 0);

        $images = $this->browser->request('GET', '/reviews/round-1/record')->filter(self::OCTOPUS);

        self::assertCount(2, $images);
        $images->each(static function (Crawler $image): void {
            self::assertSame('', $image->attr('alt'));
            self::assertNull($image->attr('role'));
            self::assertNull($image->attr('title'));
            self::assertSame('72', $image->attr('width'));
            self::assertSame('72', $image->attr('height'));
            self::assertSame('lazy', $image->attr('loading'));
        });
        self::assertMatchesRegularExpression('#^/assets/images/mascot/octopus-bowing-light-72-[\w-]+\.webp 1x, /assets/images/mascot/octopus-bowing-light-144-[\w-]+\.webp 2x$#', (string) $images->first()->attr('srcset'));
        self::assertMatchesRegularExpression('#^/assets/images/mascot/octopus-bowing-dark-72-[\w-]+\.webp 1x, /assets/images/mascot/octopus-bowing-dark-144-[\w-]+\.webp 2x$#', (string) $images->last()->attr('srcset'));
    }

    public function testTheOctopusLeavesTheOutcomeWordsAndTheChipAsTheyWere(): void
    {
        $this->givenARoundJudged(1, 0);

        $page = $this->browser->request('GET', '/reviews/round-1/record');

        self::assertSame('Outcome: Approved, decided by the quorum policy all over all 2 reviewers.', $page->filter('#round-round-1-verdict')->text());
        self::assertSame('Approved', $page->filter('#round-round-1 summary > .chip.approved')->text());
        self::assertCount(0, $page->filter('#round-round-1 summary img.character'));
    }

    public function testARoundWithChangesRequestedShowsNoOctopus(): void
    {
        $this->givenARoundJudged(1, 2);

        $verdict = $this->browser->request('GET', '/reviews/round-1/record')->filter('#round-round-1-verdict');

        self::assertSame('Outcome: Changes requested, decided by the quorum policy all over all 2 reviewers.', $verdict->text());
        self::assertNull($verdict->attr('class'));
        self::assertCount(0, $verdict->filter('img'));
    }

    public function testARoundWhoseReviewersAllFailedShowsNoOctopus(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->failReviewer('round-1', 'correctness', 'The provider is down.');
        $this->ledger->failReviewer('round-1', 'security', 'The provider is down.');
        $this->ledger->judgeRound('round-1');

        self::assertCount(0, $this->browser->request('GET', '/reviews/round-1')->filter(self::OCTOPUS));
    }

    public function testARoundStillInReviewShowsNoOctopus(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 0);

        $verdict = $this->browser->request('GET', '/reviews/round-1/record')->filter('#round-round-1-verdict');

        self::assertSame('', $verdict->text());
        self::assertCount(0, $verdict->filter('img'));
    }

    public function testAnApprovedOlderRoundShowsNoOctopusSoThePageHoldsOnlyTheNewestRoundsOne(): void
    {
        $this->givenARoundJudged(1, 0);
        $this->givenARoundJudged(2, 0);

        $page = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(0, $page->filter('#round-round-1-verdict img'));
        self::assertCount(2, $page->filter('#round-round-2-verdict '.self::OCTOPUS));
        self::assertCount(2, $page->filter('img[src*="/mascot/"]'));
    }

    public function testAnApprovedOlderRoundBeforeANewestRoundWithChangesRequestedShowsNoOctopus(): void
    {
        $this->givenARoundJudged(1, 0);
        $this->givenARoundJudged(2, 1);

        self::assertCount(0, $this->browser->request('GET', '/reviews/round-2')->filter('img[src*="/mascot/"]'));
    }

    public function testAnOlderRoundsApprovalArrivingLiveAfterANewerRoundShowsNoOctopusAsAFreshLoad(): void
    {
        $this->givenARoundJudged(1, 0);
        $this->givenARoundJudged(2, 0);
        self::assertCount(0, $this->browser->request('GET', '/reviews/round-2')->filter('#round-round-1-verdict img'));
        $review = self::getContainer()->get(LedgerReviewPages::class)->reviewOf('round-1');

        $stream = self::getContainer()->get(Environment::class)->render('dashboard/stream/round_judged.stream.html.twig', ['round' => 'round-1', 'reviewed' => $review?->rounds[0], 'review' => $review, 'progress' => new RoundProgress(2, 2, true)]);

        self::assertCount(0, new Crawler($stream)->filter('turbo-stream[target="round-round-1-verdict"] template img'));
    }

    public function testAJudgedRoundStreamedWithoutItsReviewShowsNoOctopus(): void
    {
        $stream = self::getContainer()->get(Environment::class)->render('dashboard/stream/round_judged.stream.html.twig', ['round' => 'round-1', 'reviewed' => null, 'review' => null, 'progress' => new RoundProgress(2, 2, true)]);

        self::assertCount(0, new Crawler($stream)->filter('img'));
    }

    public function testANewerRoundStillInReviewLeavesTheOlderApprovedRoundWithoutAnOctopus(): void
    {
        $this->givenARoundJudged(1, 0);
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-2', 2);

        self::assertCount(0, $this->browser->request('GET', '/reviews/round-2')->filter('img[src*="/mascot/"]'));
    }

    public function testAnOlderRoundJudgedLateWhileANewerRoundIsStillInReviewStreamsNoOctopus(): void
    {
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-1', 1);
        $this->ledger->answer('round-1', 'correctness', 0);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->startRoundOfQuorumAll('feat/thing', 'round-2', 2);
        $this->ledger->judgeRound('round-1');
        $review = self::getContainer()->get(LedgerReviewPages::class)->reviewOf('round-1');

        $stream = new Crawler(self::getContainer()->get(Environment::class)->render('dashboard/stream/round_judged.stream.html.twig', ['round' => 'round-1', 'reviewed' => $review?->rounds[0], 'review' => $review, 'progress' => new RoundProgress(2, 2, true)]));

        self::assertCount(1, $stream->filter('turbo-stream[target="round-round-1-verdict"] template'));
        self::assertCount(0, $stream->filter('turbo-stream[target="round-round-1-verdict"] template img'));
    }

    private function givenARoundJudged(int $number, int $findings): void
    {
        $round = 'round-'.$number;
        $this->ledger->startRoundOfQuorumAll('feat/thing', $round, $number);
        $this->ledger->answer($round, 'correctness', $findings);
        $this->ledger->answer($round, 'security', 0);
        $this->ledger->judgeRound($round);
    }
}
