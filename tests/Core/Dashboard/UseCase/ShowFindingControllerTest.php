<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\UseCase\ShowFinding\ShowFindingController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowFindingController::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(FindingUrgency::class)]
final class ShowFindingControllerTest extends DashboardPageTestCase
{
    public function testAFindingsPageLeadsWithItsUrgencyHeadlineAndPlace(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Urgent · one reviewer', 'The total is off by one.', 'src/A.php:7'],
            [$crawler->filter('.finding-head .kicker')->text(), $crawler->filter('.finding-head h1')->text(), $crawler->filter('.finding-head .finding-place')->text()],
        );
    }

    public function testTheRestOfTheFindingsTextExplainsWhy(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame('It drops the last item.', $crawler->filter('.finding-words')->text());
    }

    public function testEachReviewerWhoReportedItIsNamedAndTheJudgesCallFollows(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(1, $crawler->filter('.finding-reporters li')->count());
        self::assertStringContainsString('correctness', $crawler->filter('.finding-reporters li')->text());
        self::assertStringContainsString('Fix now', $crawler->filter('.finding-judge')->text());
    }

    public function testAPassCheckedBeforeIntaktKeptWhoReportedWhatSaysSo(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one.');

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(['Urgent', 0], [$crawler->filter('.finding-head .kicker')->text(), $crawler->filter('.finding-words')->count()]);
        self::assertStringStartsWith('Not recorded', $crawler->filter('#finding-who + p')->text());
    }

    public function testTheFindingsPageLeadsBackToItsReview(): void
    {
        $this->givenAFindingToFix();

        $crawler = $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertSame(['/reviews/round-1'], $crawler->filter('.finding-back a')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testAnUnknownFindingIsNotFound(): void
    {
        $this->givenAFindingToFix();

        $this->browser->request('GET', '/reviews/round-1/findings/F9');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAFindingOfARoundNotJudgedYetIsNotFound(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');

        $this->browser->request('GET', '/reviews/round-1/findings/F1');

        self::assertResponseStatusCodeSame(404);
    }

    private function givenAFindingToFix(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
        $this->ledger->reportFindings('round-1', 'correctness', ['F1']);
        $this->ledger->reportFindings('round-1', 'security', []);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one. It drops the last item.');
    }
}
