<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[CoversClass(RoundFinding::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerPayload::class)]
final class JudgedRoundFindingsPageTest extends DashboardPageTestCase
{
    public function testAJudgedFindingIsACardWithItsSeverityChipFileAndLineAndTheCallInWords(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'Take the lock first.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['P1', 'F1', 'Take the lock first.', 'src/A.php:7', 'Fix now']], $this->cardsOf($crawler->filter('#round-round-1 ul.finding-cards > li.finding-card')));
    }

    public function testAFindingsCodeSpansAreCodeAndNoLiteralBacktickIsLeft(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'Call `Lock::acquire()` before <b>`write()`</b>.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $title = $crawler->filter('#round-round-1 .finding-card .fc-title');
        self::assertSame(['Lock::acquire()', 'write()'], $title->filter('code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertSame('F1 Call Lock::acquire() before <b>write()</b>.', $title->text());
        self::assertStringNotContainsString('`', $crawler->filter('#round-round-1 .finding-cards')->text());
    }

    public function testAnUnmatchedBacktickLeavesNoLiteralBacktickInACard(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'Call `write() before returning.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringNotContainsString('`', $crawler->filter('#round-round-1 .finding-card')->text());
        self::assertSame('write()', $crawler->filter('#round-round-1 .finding-card code')->text());
    }

    public function testMarkupInAFindingsTextOrCodeStaysText(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', '<img src=x onerror=alert(1)> `<script>alert(1)</script>`');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('.finding-card img, .finding-card script'));
        self::assertSame('<script>alert(1)</script>', $crawler->filter('#round-round-1 .finding-card code')->text());
    }

    public function testADroppedFindingsCardNamesWhyItWasDropped(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'defer', 'F2' => 'reject']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([JudgeCall::FollowUp->inWords(), JudgeCall::NotActedOn->inWords()], $crawler->filter('#round-round-1 .finding-card .fc-call')->each(static fn (Crawler $call): string => $call->text()));
    }

    public function testAFindingWithoutAFileSaysItsPlaceWasNotRecorded(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('File and line not recorded', $crawler->filter('#round-round-1 .finding-card .fc-place')->text());
    }

    public function testAFindingCardKeepsTheAnchorTheNowBlockLinksTo(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F2');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('F1 F2', $crawler->filter('li.finding-card#round-round-1-finding-F1')->attr('data-finding'));
    }

    public function testNoTableHoldsTheFindingsOfAJudgedRound(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('#round-round-1 table.findings'));
    }

    public function testTheOpenFindingInTheNowBlockShowsItsCodeSpansAsCode(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'Call `write()`.');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame('P1F1 Call write().', $crawler->filter('.state-block a.sb-finding')->text());
        self::assertSame('write()', $crawler->filter('.state-block a.sb-finding code')->text());
    }

    /**
     * @return list<list<string>>
     */
    private function cardsOf(Crawler $cards): array
    {
        return $cards->each(static fn (Crawler $card): array => [
            $card->filter('.sev')->text(),
            $card->filter('.fc-id')->text(),
            $card->filter('.fc-text')->text(),
            $card->filter('.fc-place')->text(),
            $card->filter('.fc-call')->text(),
        ]);
    }
}
