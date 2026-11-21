<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\CountedQuotes;
use Intakt\Core\Review\Contract\QuotesCounted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[CoversClass(RoundFinding::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(CountedQuotes::class)]
#[UsesClass(QuotesCounted::class)]
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

    public function testAFindingsCardAndTheNowBlockShowItsEmphasisWithoutTheMarks(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'the amount is **greater than** 100, not *why*');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertSame('F1 the amount is greater than 100, not why', $crawler->filter('#round-round-1 .finding-card .fc-title')->text());
        self::assertSame('P1F1 the amount is greater than 100, not why', $crawler->filter('.state-block a.sb-finding')->text());
    }

    #[TestWith(['Copy *.php…'])]
    #[TestWith(['Match **kwargs…'])]
    #[TestWith(['Use _private and more…'])]
    #[TestWith(['Copy *.php and *.twig…'])]
    public function testACardEndingInAnEllipsisTheReviewerWroteKeepsItsWildcards(string $written): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', $written);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame($written, $crawler->filter('#round-round-1 .finding-card .fc-text')->text());
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

    public function testAFindingQuotingANameAbsentFromTheChangeIsShownAsDroppedWithItsReason(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Call `ghostName` first <b>now</b>.', 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([['P2', 'F1', 'Call ghostName first <b>now</b>.', 'src/A.php:3', 'Quote not found in the code']], $this->cardsOf($crawler->filter('#round-round-1 [data-dropped]')));
        self::assertSame('Dropped before the judge: none of its 1 quoted code fragments is in the change', $crawler->filter('#round-round-1 [data-dropped] .fc-reason')->text());
    }

    public function testTheQuotedCodeDisclosureOfADroppedFindingStartsClosed(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Call `ghostName`.', 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $disclosure = $crawler->filter('#round-round-1 [data-dropped] details.fc-quotes');
        self::assertSame('Quoted code', $disclosure->filter('summary')->text());
        self::assertNull($disclosure->attr('open'));
    }

    public function testTheQuotedCodeDisclosureEscapesTheQuoteAndTheReason(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Call `<img src=x onerror=ghost(1)>`.', '<b onclick=alert(1)>none</b>');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $disclosure = $crawler->filter('#round-round-1 [data-dropped] details.fc-quotes');
        self::assertCount(0, $disclosure->filter('img, b'));
        self::assertSame(['<img src=x onerror=ghost(1)>'], $this->quotesIn($disclosure));
        self::assertSame('Dropped before the judge: <b onclick=alert(1)>none</b>', $disclosure->filter('.fc-reason')->text());
    }

    public function testADroppedFindingWithNoCheckedQuotesShowsOnlyTheReason(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Read `orders.log` at line `42`.', 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $disclosure = $crawler->filter('#round-round-1 [data-dropped] details.fc-quotes');
        self::assertSame('Quoted code Dropped before the judge: none of its 1 quoted code fragments is in the change', $disclosure->text());
    }

    public function testADroppedFindingWithAStrayBacktickListsOnlyTheQuoteTheCheckCounted(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'A stray ` appears before `ghostName`.', 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['ghostName'], $this->quotesIn($crawler->filter('#round-round-1 [data-dropped] details.fc-quotes')));
    }

    public function testADroppedFindingWithAFencedSuggestedFixListsNoLineOfTheBlock(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', "`ghostName` is not defined. Suggested fix:\n```js\nrun(`otherName`);\n```", 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['ghostName'], $this->quotesIn($crawler->filter('#round-round-1 [data-dropped] details.fc-quotes')));
    }

    public function testTheListedQuotesAreAsManyAsTheRecordedReasonCountsAndSayWhatIsNotChecked(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Substring matches can include other customers (for example, `ann` matching `joanne`), and the relative `orders.log` depends on where it runs.', 'none of its 2 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $disclosure = $crawler->filter('#round-round-1 [data-dropped] details.fc-quotes');
        self::assertSame(['ann', 'joanne'], $this->quotesIn($disclosure));
        self::assertSame('File names, numbers and suggested-fix blocks are not checked.', $disclosure->filter('.fc-unchecked')->text());
    }

    public function testARepeatedQuoteIsListedEachTimeTheRecordedReasonCountsIt(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Call `ghostName` once, not `ghostName` twice.', 'none of its 2 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['ghostName', 'ghostName'], $this->quotesIn($crawler->filter('#round-round-1 [data-dropped] details.fc-quotes')));
    }

    public function testAQuoteThatWrapsOntoTheNextLineKeepsItsLineBreak(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', "Call `ghostName(\n    other)` here.", 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame("ghostName(\n    other)", $crawler->filter('#round-round-1 [data-dropped] details.fc-quotes .fc-quote-list code')->text(normalizeWhitespace: false));
    }

    /**
     * @return list<string>
     */
    private function quotesIn(Crawler $disclosure): array
    {
        return $disclosure->filter('.fc-quote-list code')->each(static fn (Crawler $code): string => $code->text());
    }

    public function testADroppedFindingCanBeReachedByFindingNavigation(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Call `ghostName`.', 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['F1'], $crawler->filter('#round-round-1 li[data-finding~="F1"]')->each(static fn (Crawler $card): string => (string) $card->attr('data-dropped')));
    }

    public function testARoundWhoseOnlyFindingWasDroppedDoesNotSayTheReviewersReportedNone(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeWithOnlyADroppedFinding('round-1', 'Call `ghostName`.', 'none of its 1 quoted code fragments is in the change');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertStringNotContainsString('reported no findings', $crawler->filter('#round-round-1-judged')->text());
    }

    public function testARoundWithNoDroppedFindingShowsNoDroppedCard(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'Take the lock first.');

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(0, $crawler->filter('[data-dropped]'));
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
