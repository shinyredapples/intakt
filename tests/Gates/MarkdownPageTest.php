<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\MarkdownDelimiterRow;
use Intakt\Gates\MarkdownLineKind;
use Intakt\Gates\MarkdownLineKinds;
use Intakt\Gates\MarkdownListIndents;
use Intakt\Gates\MarkdownPage;
use Intakt\Gates\MarkdownParagraph;
use Intakt\Gates\MarkdownSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownPage::class)]
#[CoversClass(MarkdownLineKind::class)]
#[CoversClass(MarkdownLineKinds::class)]
#[CoversClass(MarkdownListIndents::class)]
#[CoversClass(MarkdownDelimiterRow::class)]
#[UsesClass(MarkdownParagraph::class)]
#[UsesClass(MarkdownSummary::class)]
final class MarkdownPageTest extends TestCase
{
    public function testEachParagraphCarriesItsWordsAndItsKey(): void
    {
        $paragraphs = $this->page('A paragraph of prose.', '', 'Another one.')->paragraphs();

        self::assertSame(['A paragraph of prose.', 'Another one.'], $this->texts($paragraphs));
        self::assertSame([4, 2], array_map(static fn (MarkdownParagraph $paragraph): int => $paragraph->words, $paragraphs));
        self::assertSame('f99aa4efdc8bdf01', $paragraphs[0]->key());
    }

    public function testARewrappedParagraphKeepsItsKey(): void
    {
        $wrapped = $this->page('A paragraph', '  of prose.')->paragraphs();

        self::assertSame('f99aa4efdc8bdf01', $wrapped[0]->key());
    }

    public function testOnlyTokensWithALetterOrADigitCountAsWords(): void
    {
        self::assertSame(3, $this->page('A - b | c')->paragraphs()[0]->words);
    }

    public function testEachListItemIsAParagraphOfItsOwnWithoutItsMarker(): void
    {
        $paragraphs = $this->page('- one two', '  continued', '1. three')->paragraphs();

        self::assertSame(['one two continued', 'three'], $this->texts($paragraphs));
    }

    public function testThePageWordsCountHeadingsTablesAndProseButNotCode(): void
    {
        $page = $this->page('# Title here', '', '| a | b |', '|---|---|', '', 'Some prose.', '', '    code not counted');

        self::assertSame(6, $page->words());
    }

    public function testASummaryRightAfterThePageTitleOpensThePage(): void
    {
        $summary = $this->page('## Title', '', '### In short', '', 'One two three.', '', '## Next', 'More.')->summary();

        self::assertEquals(new MarkdownSummary(3, true), $summary);
    }

    public function testASummaryUnderALaterHeadingIsReadButDoesNotOpenThePage(): void
    {
        $summary = $this->page('## Title', '', 'Intro first.', '', '### In short', '', 'One two.')->summary();

        self::assertEquals(new MarkdownSummary(2, false), $summary);
    }

    public function testAnEmptyInShortSectionCountsNoWords(): void
    {
        $summary = $this->page('# Title', '### In short', '', '### Next', 'Text after.')->summary();

        self::assertEquals(new MarkdownSummary(0, true), $summary);
    }

    public function testAnOversizedSummaryCountsEveryWordUpToTheNextHeadingOfItsLevel(): void
    {
        $summary = $this->page('# Title', '### In short', $this->words(130), '#### Detail', $this->words(10), '### Next', $this->words(5))->summary();

        self::assertEquals(new MarkdownSummary(141, true), $summary);
    }

    public function testAPageWithoutAnInShortSectionHasNoSummary(): void
    {
        self::assertNull($this->page('# Title', 'Prose.')->summary());
    }

    public function testAQuotedInShortSectionIsRead(): void
    {
        $summary = $this->page('# Title', '', '> ### In short', '> One two three.', '', '## Next', 'More.')->summary();

        self::assertEquals(new MarkdownSummary(3, true), $summary);
    }

    public function testAHeadingInsideAQuoteDoesNotEndTheInShortSection(): void
    {
        $summary = $this->page('## Title', '### In short', 'One two.', '', '> ### Aside', '> '.$this->words(130), '', '## Next', 'More.')->summary();

        self::assertEquals(new MarkdownSummary(133, true), $summary);
    }

    public function testProseThatStartsWithAPipeIsAParagraph(): void
    {
        $paragraphs = $this->page('| is the pipe operator in PHP', '', 'After.')->paragraphs();

        self::assertSame(['| is the pipe operator in PHP', 'After.'], $this->texts($paragraphs));
    }

    public function testATableWithoutOuterPipesIsNotProse(): void
    {
        $paragraphs = $this->page('Name | Value', '--- | ---', 'a | b', 'no pipe row', '', 'After.')->paragraphs();

        self::assertSame(['After.'], $this->texts($paragraphs));
    }

    public function testIndentedCodeIsNotCounted(): void
    {
        $page = $this->page('Intro.', '', '    code here', '', '    more code', '', 'After.');

        self::assertSame(['Intro.', 'After.'], $this->texts($page->paragraphs()));
        self::assertSame(2, $page->words());
    }

    public function testAnIndentedLineRightAfterProseContinuesTheParagraph(): void
    {
        self::assertSame(['Intro more'], $this->texts($this->page('Intro', '    more')->paragraphs()));
    }

    public function testAnIndentedLineInsideAListIsTheItemsContent(): void
    {
        $paragraphs = $this->page('- An item.', '', '    Its second paragraph.')->paragraphs();

        self::assertSame(['An item.', 'Its second paragraph.'], $this->texts($paragraphs));
    }

    public function testProseDirectlyAfterALoneFramedLineStillCounts(): void
    {
        $paragraphs = $this->page('## Section', '| Symfony Lock | used | T1 #1 | `src/` |', 'Prose right after it.')->paragraphs();

        self::assertSame(['Prose right after it.'], $this->texts($paragraphs));
    }

    public function testSeveralFramedLinesWithoutADelimiterRowAreTableRows(): void
    {
        $paragraphs = $this->page('| first row |', '| second row |', '', 'After.')->paragraphs();

        self::assertSame(['After.'], $this->texts($paragraphs));
    }

    public function testAQuotedLineDirectlyAfterATableEndsTheTableSoTheQuoteIsMeasured(): void
    {
        $paragraphs = $this->page('| a | b |', '|---|---|', '| c | d |', '> '.$this->words(81))->paragraphs();

        self::assertSame([81], array_map(static fn (MarkdownParagraph $paragraph): int => $paragraph->words, $paragraphs));
    }

    public function testATableEndsAtAHeading(): void
    {
        $paragraphs = $this->page('| a | b |', '|---|---|', '## Next', 'Prose.')->paragraphs();

        self::assertSame(['Prose.'], $this->texts($paragraphs));
    }

    public function testATableEndsAtAListItem(): void
    {
        $paragraphs = $this->page('| a | b |', '|---|---|', '- an item', 'continued')->paragraphs();

        self::assertSame(['an item continued'], $this->texts($paragraphs));
    }

    public function testAnUnquotedLineRightAfterAQuotedParagraphContinuesIt(): void
    {
        $paragraphs = $this->page('> Quoted start', 'lazy end', '', 'After.')->paragraphs();

        self::assertSame(['Quoted start lazy end', 'After.'], $this->texts($paragraphs));
    }

    public function testAThematicBreakIsNoListItemAndNoWord(): void
    {
        $page = $this->page('Before.', '', '* * *', '', 'After.');

        self::assertSame(['Before.', 'After.'], $this->texts($page->paragraphs()));
        self::assertSame(2, $page->words());
    }

    public function testADelimiterRowWithFewerColumnsThanTheHeaderStartsNoTable(): void
    {
        $page = $this->page('First | Second', '| --- |', 'This prose should be measured.');

        self::assertSame(['First | Second', 'This prose should be measured.'], $this->texts($page->paragraphs()));
    }

    public function testADelimiterCellWithoutAHyphenStartsNoTable(): void
    {
        $page = $this->page('First | Second', '--- | :', 'This prose should be measured.');

        self::assertSame(['First | Second --- | : This prose should be measured.'], $this->texts($page->paragraphs()));
    }

    public function testAValidDelimiterRowStillStartsATable(): void
    {
        $page = $this->page('First | Second', ':--- | ---:', 'Row | cell', '', 'Prose.');

        self::assertSame(['Prose.'], $this->texts($page->paragraphs()));
    }

    public function testHashesRightAfterTheHeadingTextAreNoClosingSequence(): void
    {
        $summary = $this->page('# Title', '## In short###', 'Tiny.', '## In short', $this->words(130))->summary();

        self::assertEquals(new MarkdownSummary(130, false), $summary);
    }

    public function testAClosingSequenceAfterASpaceStillMarksTheSummary(): void
    {
        $summary = $this->page('# Title', '## In short ##', 'One two.')->summary();

        self::assertEquals(new MarkdownSummary(2, true), $summary);
    }

    public function testAQuotedSummaryHeadingAfterATabIsRead(): void
    {
        $summary = $this->page('# Title', ">\t## In short", '> One two.', '## Next')->summary();

        self::assertEquals(new MarkdownSummary(2, true), $summary);
    }

    public function testIndentedCodeInsideAListItemIsNotCounted(): void
    {
        $page = $this->page('- Item.', '', '      code words');

        self::assertSame(['Item.'], $this->texts($page->paragraphs()));
        self::assertSame(1, $page->words());
    }

    public function testAParagraphIndentedToTheListItemsContentIsProse(): void
    {
        $page = $this->page('- Item.', '', '  More of the item.');

        self::assertSame(['Item.', 'More of the item.'], $this->texts($page->paragraphs()));
    }

    public function testADelimiterRowIndentedFourSpacesContinuesTheParagraph(): void
    {
        $page = $this->page('Name | Value', '    --- | ---', 'This prose should be measured.');

        self::assertSame(['Name | Value --- | --- This prose should be measured.'], $this->texts($page->paragraphs()));
    }

    public function testADelimiterRowIndentedThreeSpacesStillStartsATable(): void
    {
        $page = $this->page('Name | Value', '   --- | ---', 'Row | cell', '', 'Prose.');

        self::assertSame(['Prose.'], $this->texts($page->paragraphs()));
    }

    public function testAListItemWhoseFirstLineIsIndentedCodeIsNotCounted(): void
    {
        $page = $this->page('-     code words', '', 'After.');

        self::assertSame(['After.'], $this->texts($page->paragraphs()));
        self::assertSame(1, $page->words());
    }

    public function testAListItemWithAShortGapIsProse(): void
    {
        $page = $this->page('-    item words', '', 'After.');

        self::assertSame(['item words', 'After.'], $this->texts($page->paragraphs()));
    }

    public function testAHeadingInsideAListItemKeepsTheItemOpenForTheProseAfterIt(): void
    {
        $page = $this->page('- Item.', '', '  ## Heading', '', '    Item prose.');

        self::assertSame(['Item.', 'Item prose.'], $this->texts($page->paragraphs()));
    }

    public function testAHeadingAtTheMarginEndsTheListSoIndentedLinesAfterItAreCode(): void
    {
        $page = $this->page('- Item.', '', '## Heading', '', '    code words');

        self::assertSame(['Item.'], $this->texts($page->paragraphs()));
    }

    private function page(string ...$lines): MarkdownPage
    {
        return new MarkdownPage('docs/page.md', array_values($lines));
    }

    private function words(int $count): string
    {
        return trim(str_repeat('word ', $count));
    }

    /**
     * @param list<MarkdownParagraph> $paragraphs
     *
     * @return list<string>
     */
    private function texts(array $paragraphs): array
    {
        return array_map(static fn (MarkdownParagraph $paragraph): string => $paragraph->text, $paragraphs);
    }
}
