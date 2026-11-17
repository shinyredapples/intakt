<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
final class ReviewPageOctopusTest extends DashboardPageTestCase
{
    private const string OCTOPUS = 'img.character[src*="/mascot/octopus-bowing-"]';

    public function testAnApprovedNewestRoundShowsTheBowingOctopusBesideItsOutcome(): void
    {
        $this->givenARoundJudged(1, 0);

        $verdict = $this->browser->request('GET', '/reviews/round-1')->filter('#round-round-1-verdict');

        self::assertSame('character-row', $verdict->attr('class'));
        self::assertSame(['character character-light', 'character character-dark'], $verdict->filter(self::OCTOPUS)->each(static fn (Crawler $image): ?string => $image->attr('class')));
        self::assertSame('p', $verdict->filter(self::OCTOPUS)->last()->nextAll()->nodeName());
    }

    public function testTheOctopusIsADecorative72PxImageWithItsDoubleForSharpScreens(): void
    {
        $this->givenARoundJudged(1, 0);

        $images = $this->browser->request('GET', '/reviews/round-1')->filter(self::OCTOPUS);

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

        $page = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('Outcome: Approved, decided by the quorum policy all over all 2 reviewers.', $page->filter('#round-round-1-verdict')->text());
        self::assertSame('Approved', $page->filter('#round-round-1 summary > .chip.approved')->text());
        self::assertCount(0, $page->filter('#round-round-1 summary img.character'));
    }

    public function testARoundWithChangesRequestedShowsNoOctopus(): void
    {
        $this->givenARoundJudged(1, 2);

        $verdict = $this->browser->request('GET', '/reviews/round-1')->filter('#round-round-1-verdict');

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

        $verdict = $this->browser->request('GET', '/reviews/round-1')->filter('#round-round-1-verdict');

        self::assertSame('', $verdict->text());
        self::assertCount(0, $verdict->filter('img'));
    }

    public function testAnApprovedOlderRoundShowsNoOctopusSoThePageHoldsOnlyTheNewestRoundsOne(): void
    {
        $this->givenARoundJudged(1, 0);
        $this->givenARoundJudged(2, 0);

        $page = $this->browser->request('GET', '/reviews/round-2');

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

    private function givenARoundJudged(int $number, int $findings): void
    {
        $round = 'round-'.$number;
        $this->ledger->startRoundOfQuorumAll('feat/thing', $round, $number);
        $this->ledger->answer($round, 'correctness', $findings);
        $this->ledger->answer($round, 'security', 0);
        $this->ledger->judgeRound($round);
    }
}
