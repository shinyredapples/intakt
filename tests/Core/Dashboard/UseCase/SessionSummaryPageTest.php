<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\ToolCallCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ShowReviewController::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(Cost::class)]
#[UsesClass(Finding::class)]
#[UsesClass(SessionSummary::class)]
#[UsesClass(ToolCallCount::class)]
final class SessionSummaryPageTest extends DashboardPageTestCase
{
    public function testTheSummaryIsPinnedUnderTheLiveLogOfTheRunningRound(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['h2', 'div', 'section'], $crawler->filter('section.log-pane')->children()->each(static fn (Crawler $part): string => $part->nodeName()));
        self::assertCount(1, $crawler->filter('section.log-pane > .log-main #round-round-2-live'));
        self::assertSame('Session summary', $crawler->filter('section.log-pane > section.log-summary')->attr('aria-label'));
    }

    public function testTheSummaryCountsTheToolCallsByKindWithTheirTotal(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame([['Directory listings', '1'], ['File reads', '4'], ['Total', '5']], $this->pairsOf($crawler->filter('.log-summary .ls-tools')));
    }

    public function testTheSummaryShowsTheCostOfTheRoundAndOfTheReviewSoFar(): void
    {
        $this->ledger->useTokens('round-1', 1000, 300);
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame([['This round', '0.6100 USD, price date unknown'], ['Review so far', 'unknown, no price for model-a']], $this->pairsOf($crawler->filter('.log-summary .ls-cost')));
    }

    public function testTheSummaryListsEachFileReadOnceInMonospace(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['src/PushGate.php', 'tests/PushGateTest.php'], $crawler->filter('.log-summary .ls-files li .mono')->each(static fn (Crawler $file): string => $file->text()));
    }

    public function testTheSummaryListsTheFindingsTheRoundCheckedAndReportedWithTheirState(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame([
            'F1 P1 Read the status per push. Checked, resolved',
            'F2 P3 Name the cache window. Open',
            'F4 P2 Order the queue by hold time. Reported',
        ], $this->linesOf($crawler->filter('.log-summary .ls-findings li')));
    }

    public function testEachFindingIdIsAButtonNamingTheFindingToHighlight(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        $buttons = $crawler->filter('.log-summary .ls-findings li .mono button[type="button"]');
        self::assertSame(['F1', 'F2', 'F4'], $buttons->each(static fn (Crawler $button): string => (string) $button->attr('data-highlight-finding')));
    }

    public function testTheTimelineEntryOfAJudgedFindingNamesItsFindingForTheHighlight(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['F1', 'F2'], $crawler->filter('#round-round-1 ul.finding-cards li[data-finding]')->each(static fn (Crawler $card): string => (string) $card->attr('data-finding')));
    }

    public function testAFindingTheRoundCheckedAndReportedAgainIsListedOnceAsOpen(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary(reported: [$this->finding('F2', Severity::P3, 'Name the cache window again.')]));

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['F1 P1 Read the status per push. Checked, resolved', 'F2 P3 Name the cache window. Open'], $this->linesOf($crawler->filter('.log-summary .ls-findings li')));
    }

    public function testASummaryWithNothingRecordedYetSaysSoInEachPart(): void
    {
        $this->givenTheRunningRoundRecorded(new SessionSummary([], Cost::none(), [], [], [], [], false));

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['No tool call yet.', 'No file read yet.', 'No finding to check, and none reported yet. Reported findings appear once every reviewer is in.'], $this->linesOf($crawler->filter('.log-summary p.muted')));
    }

    public function testASummaryWithoutToolCallsShowsNoToolList(): void
    {
        $this->givenTheRunningRoundRecorded(new SessionSummary([], Cost::none(), [], [], [], [], false));

        $crawler = $this->browser->request('GET', '/reviews/round-2');

        self::assertCount(0, $crawler->filter('.log-summary .ls-tools'));
    }

    public function testARoundIntaktHoldsNoRecordOfSaysTheSummaryIsNotRecorded(): void
    {
        $this->givenTheRunningRoundRecorded(null);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame('No session summary: Intakt holds no record of this round\'s tool calls and findings.', $crawler->filter('.log-summary p')->text());
        self::assertCount(0, $crawler->filter('.log-summary h3'));
    }

    public function testAReviewWithEveryRoundJudgedShowsNoSummary(): void
    {
        $this->givenTheRunningRoundRecorded($this->summary());
        $this->ledger->judgeFindings('round-2', []);

        $this->browser->request('GET', '/reviews/round-2');

        self::assertSelectorNotExists('.log-summary');
    }

    public function testAFindingTextAndAFileNameWithMarkupAreEscaped(): void
    {
        $this->givenTheRunningRoundRecorded(new SessionSummary([], Cost::none(), ['<b>x</b>.php'], [$this->finding('F1', Severity::P2, '<script>alert(1)</script>')], [], [], false));

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame('<b>x</b>.php', $crawler->filter('.ls-files .mono')->text());
        self::assertStringContainsString('<script>alert(1)</script>', $crawler->filter('.ls-findings li')->text());
        self::assertCount(0, $crawler->filter('.log-summary b, .log-summary script'));
    }

    public function testABacktickSpanInAFindingOfTheSummaryShowsAsCode(): void
    {
        $this->givenTheRunningRoundRecorded(new SessionSummary([], Cost::none(), [], [$this->finding('F1', Severity::P2, 'Call `Lock::acquire()` first.')], [], [], false));

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertSame(['Lock::acquire()'], $crawler->filter('.ls-findings .ls-text code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertStringNotContainsString('`', $crawler->filter('.ls-findings')->text());
    }

    public function testAFindingMergedIntoAnActEntryReachesThatEntryFromItsHighlightButton(): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeOneEntryOf('round-1', 'F1', 'F2');
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturn(new SessionSummary([], Cost::none(), [], [$this->finding('F2', Severity::P2, 'Broken.')], [], [], false));
        self::getContainer()->set(SessionSummaries::class, $summaries);

        $crawler = $this->browser->request('GET', '/reviews/round-2/record');

        self::assertCount(1, $crawler->filter('.ls-findings button[data-highlight-finding="F2"]'));
        self::assertSame(['F1'], $crawler->filter('#round-round-1 li[data-finding~="F2"] .fc-id')->each(static fn (Crawler $id): string => $id->text()));
    }

    public function testTheStylesheetKeepsTheSummaryInViewWhileTheLogScrollsAboveIt(): void
    {
        $sheet = (string) file_get_contents(dirname(__DIR__, 4).'/assets/styles/components.css');

        self::assertMatchesRegularExpression('/\.log \{[^}]*display: flex; flex-direction: column;[^}]*overflow: hidden;/', $sheet);
        self::assertMatchesRegularExpression('/\.log-main \{[^}]*flex: 1 1 auto; min-height: 0; overflow: auto;/', $sheet);
        self::assertMatchesRegularExpression('/\.log-summary \{[^}]*flex: none;/', $sheet);
    }

    public function testTheStylesheetKeepsEachFindingIdOnOneLineBesideALongFindingText(): void
    {
        $sheet = (string) file_get_contents(dirname(__DIR__, 4).'/assets/styles/components.css');

        self::assertMatchesRegularExpression('/\.ls-findings \.mono \{[^}]*flex: none; min-width: 4ch;[^}]*white-space: nowrap;/', $sheet);
    }

    /**
     * @param list<Finding>|null $reported
     */
    private function summary(?array $reported = null): SessionSummary
    {
        $checked = $this->finding('F1', Severity::P1, 'Read the status per push.');
        $open = $this->finding('F2', Severity::P3, 'Name the cache window.');

        return new SessionSummary(
            [new ToolCallCount('list_directory', 1), new ToolCallCount('read_file', 4)],
            Cost::inUsd(0.61),
            ['src/PushGate.php', 'tests/PushGateTest.php'],
            [$checked, $open],
            $reported ?? [$this->finding('F4', Severity::P2, 'Order the queue by hold time.')],
            [$checked],
            false,
        );
    }

    private function givenTheRunningRoundRecorded(?SessionSummary $summary): void
    {
        $this->ledger->startRoundOf('feat/thing', 'round-1', 1);
        $this->ledger->judgeFindings('round-1', ['F1' => 'act', 'F2' => 'act']);
        $this->ledger->startRoundOf('feat/thing', 'round-2', 2);
        $this->ledger->startReviewer('round-2', 'correctness');

        $summaries = self::createStub(SessionSummaries::class);
        $summaries->method('ofRound')->willReturnMap([['round-2', $summary]]);
        self::getContainer()->set(SessionSummaries::class, $summaries);
    }

    private function finding(string $id, Severity $severity, string $text): Finding
    {
        return new Finding($id, $severity, 'src/PushGate.php', 12, null, $text, FixableBy::Builder, false, null, null);
    }

    /**
     * @return list<list<string>>
     */
    private function pairsOf(Crawler $list): array
    {
        return $list->filter('dt')->each(static fn (Crawler $term): array => [$term->text(), self::wordsOf($term->nextAll()->first())]);
    }

    // A label's tooltip words are inside it, so they are left out to read the figure as a person sees it.
    private static function wordsOf(Crawler $node): string
    {
        $tips = $node->filter('.figure-kind-tip')->each(static fn (Crawler $tip): string => $tip->text());

        return str_replace($tips, '', $node->text());
    }

    /**
     * @return list<string>
     */
    private function linesOf(Crawler $items): array
    {
        return $items->each(static fn (Crawler $item): string => trim(preg_replace('/\s+/', ' ', $item->text()) ?? ''));
    }
}
