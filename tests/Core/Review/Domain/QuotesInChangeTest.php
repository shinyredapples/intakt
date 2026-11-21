<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\QuotesInChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuotesInChange::class)]
#[UsesClass(Finding::class)]
final class QuotesInChangeTest extends TestCase
{
    private const string CHANGE = <<<'JS'
        diff --git a/src/orders.js b/src/orders.js
        +export function orderLog(customerName) {
        +    execSync('grep ' + customerName + ' orders.log');
        +}
        JS;

    public function testAFindingQuotingANameAbsentFromTheChangeIsDropped(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('`grepName` is not defined.'), self::CHANGE);

        self::assertSame('none of its 1 quoted code fragments is in the change', $reason);
    }

    public function testAFindingWhoseQuoteIsInTheChangeIsKept(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('`customerName` reaches `execSync` unescaped.'), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAFindingWithoutAQuoteIsLeftToTheJudge(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('The shell command is built from user input.'), self::CHANGE);

        self::assertNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symbols(): iterable
    {
        yield 'an absent name' => ['grepName'];
        yield 'a name with words' => ['orderLog (exported function)'];
        yield 'free text' => ['execSync call in orderLog'];
        yield 'a call' => ['execSync(cmd)'];
    }

    #[DataProvider('symbols')]
    public function testAFindingWithoutAQuoteIsKeptWhateverItsSymbol(string $symbol): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('The shell command is built from user input.', $symbol), self::CHANGE);

        self::assertNull($reason);
    }

    public function testASymbolInTheChangeNeverKeepsAFindingWhoseQuotedNameIsAbsent(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('`grepName` is not defined.', 'orderLog'), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAFindingWithOneAbsentAndOnePresentQuoteIsKept(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('Pass `customerName` through `escapeShellArg` first.'), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAQuoteIsFoundByAnyOfItsNames(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('Write `escapeShellArg(customerName)` instead.'), self::CHANGE);

        self::assertNull($reason);
    }

    public function testANameIsFoundOnlyAsAWholeName(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('`customer` is not checked.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAQuoteInsideAFencedBlockIsNotChecked(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("Escape the argument:\n```js\nrun(`grepName`);\n```"), self::CHANGE);

        self::assertNull($reason);
    }

    public function testThreeBackticksInsideAFencedLineDoNotCloseTheBlock(): void
    {
        $text = "Suggested fix:\n```js\nconst marker = '```';\nescapeArgument(`suggestedName`);\n```";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAQuoteAfterAClosedFencedBlockIsChecked(): void
    {
        $text = "Suggested fix:\n~~~\nrun(`customerName`);\n~~~~\n`grepName` is not defined.";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testALineStartingWithAnInlineTripleBacktickQuoteThatClosesOnTheSameLineOpensNoFence(): void
    {
        $text = "```grepName``` is not defined.\nThe call to `otherName` fails too.";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertSame('none of its 1 quoted code fragments is in the change', $reason);
    }

    public function testAnInlineTripleBacktickQuoteThatClosesOnTheSameLineIsStillReadAsAQuote(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("```customerName``` is fine.\n`grepName` is not defined."), self::CHANGE);

        self::assertNull($reason);
    }

    public function testABacktickFenceWithAnInfoStringStillRunsToItsClosingLine(): void
    {
        $text = "Suggested fix:\n```js title\nrun(`customerName`);\n```\n`grepName` is not defined.";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertSame('none of its 1 quoted code fragments is in the change', $reason);
    }

    public function testATildeFenceWhoseInfoStringHoldsBackticksStillOpensAFence(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("Suggested fix:\n~~~ `js`\nrun(`grepName`);\n~~~"), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAFenceIndentedUnderANumberedListItemIsLeftOut(): void
    {
        $text = "1. Shell injection.\n    ```js\n    escapeArgument(`suggestedName`);\n    ```";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAQuoteAfterAnIndentedClosedFencedBlockIsChecked(): void
    {
        $text = "1. Suggested fix:\n\t```js\n\trun(`customerName`);\n\t```\n`grepName` is not defined.";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNotNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockquotedFences(): iterable
    {
        yield 'a blockquote' => ["Suggested fix:\n> ```js\n> escapeArgument(`suggestedName`);\n> ```"];
        yield 'a nested blockquote' => ["Suggested fix:\n> > ```js\n> > escapeArgument(`suggestedName`);\n> > ```"];
        yield 'markers without spaces' => ["Suggested fix:\n>>```js\n>>escapeArgument(`suggestedName`);\n>>```"];
        yield 'a tab after the marker' => ["Suggested fix:\n>\t~~~\n>\tescapeArgument(`suggestedName`);\n>\t~~~"];
        yield 'an indented blockquote with CRLF line ends' => ["1. Fix:\r\n   > ```js\r\n   > escapeArgument(`suggestedName`);\r\n   > ```"];
    }

    #[DataProvider('blockquotedFences')]
    public function testAFindingWhoseOnlyQuoteIsInsideABlockquotedFenceIsKept(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentQuotesAfterAClosedBlockquotedFence(): iterable
    {
        yield 'a blockquote' => ["> ```js\n> run(`customerName`);\n> ```\n`grepName` is not defined."];
        yield 'a nested blockquote' => ["> > ~~~\n> > run(`customerName`);\n> > ~~~\n`grepName` is not defined."];
    }

    #[DataProvider('absentQuotesAfterAClosedBlockquotedFence')]
    public function testAnAbsentQuoteAfterAClosedBlockquotedFenceIsDropped(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertSame('none of its 1 quoted code fragments is in the change', $reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentQuotesAfterAFenceClosedWithAnotherLineEnd(): iterable
    {
        yield 'a backtick fence with CRLF line ends' => ["```js\r\nrun(`customerName`);\r\n```\r\n`grepName` is not defined."];
        yield 'a tilde fence with CRLF line ends' => ["Fix:\r\n~~~\r\nrun(`customerName`);\r\n~~~\r\n`grepName` is not defined."];
        yield 'a backtick fence with CR line ends' => ["```js\rrun(`customerName`);\r```\r`grepName` is not defined."];
        yield 'a CRLF fence at the end, after the quote' => ["`grepName` is not defined.\r\n```js\r\nrun(`customerName`);\r\n```"];
        yield 'an indented fence with CRLF line ends' => ["1. Fix:\r\n    ```js\r\n    run(`customerName`);\r\n    ```\r\n`grepName` is not defined."];
    }

    #[DataProvider('absentQuotesAfterAFenceClosedWithAnotherLineEnd')]
    public function testAnAbsentQuoteAfterAFenceClosedWithACrlfOrCrLineEndIsDropped(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertSame('none of its 1 quoted code fragments is in the change', $reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotesInsideAFenceWithAnotherLineEnd(): iterable
    {
        yield 'CRLF line ends' => ["Escape the argument:\r\n```js\r\nrun(`grepName`);\r\n```\r\n`customerName` is unescaped."];
        yield 'CR line ends' => ["Escape the argument:\r```js\rrun(`grepName`);\r```\r`customerName` is unescaped."];
    }

    #[DataProvider('quotesInsideAFenceWithAnotherLineEnd')]
    public function testAQuoteInsideAFenceWithCrlfOrCrLineEndsIsNotChecked(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAQuoteOnALineEndingInCrlfIsChecked(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("The name is wrong.\r\n`grepName` is not defined.\r\n"), self::CHANGE);

        self::assertNotNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotesNamingNoCode(): iterable
    {
        yield 'a path' => ['Add a test to `tests/orders.test.js`.'];
        yield 'a file name' => ['Add `ordersTest.js`.'];
        yield 'a number' => ['The limit should be `4096`.'];
        yield 'a hexadecimal number' => ['The mode should be `0x1F`.'];
        yield 'no name at all' => ['Use `===` here.'];
    }

    #[DataProvider('quotesNamingNoCode')]
    public function testAQuoteThatNamesNoCodeIsNotChecked(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAnUncheckedQuoteNeverKeepsAFindingWhoseCheckedQuoteIsAbsent(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('`grepName` in `src/orders.js` is not defined.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testADoubleBacktickQuoteIsChecked(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('``grepName`` is not defined.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAFindingWhoseQuotesAreInTheChangeIsKeptWhenOneInlineQuoteWrapsALine(): void
    {
        $text = "`orderLog(\ncustomerName)` builds the command; wrap `customerName` in `execSync` escaping.";

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAnAbsentQuoteThatWrapsALineIsDropped(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("`grepName(\ncustomer)` is not defined."), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAQuoteNeverRunsAcrossABlankLine(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("Use `customerName\n \nhere` and `grepName` is not defined."), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAFindingWithAPresentQuoteIsKeptAfterALoneBacktick(): void
    {
        $text = 'The ` character breaks it: `customerName` reaches `execSync`.';

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAnAbsentQuoteAfterALoneBacktickIsDropped(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('The ` character: `grepName` is not defined.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function doubleBacktickQuotesInTheChange(): iterable
    {
        yield 'padded on both sides' => ['`` customerName `` reaches it; `grepName` is unrelated.'];
        yield 'a padded call' => ['`` execSync(cmd) `` runs it; `grepName` is unrelated.'];
        yield 'padded around a template literal' => ['`` `grep ${customerName}` `` is the command; `grepName` is unrelated.'];
        yield 'padded after the opening pair only' => ['`` customerName`` reaches it; `grepName` is unrelated.'];
        yield 'padded before the closing pair only' => ['``customerName `` reaches it; `grepName` is unrelated.'];
        yield 'padded by line ends' => ["``\ncustomerName\n`` reaches it; `grepName` is unrelated."];
        yield 'not padded' => ['``customerName`` reaches it; `grepName` is unrelated.'];
        yield 'padded, after a lone pair' => ['An empty `` literal, then `` customerName `` reaches it; `grepName` is unrelated.'];
        yield 'not padded, after a lone pair' => ['An empty `` literal, then ``customerName`` reaches it; `grepName` is unrelated.'];
    }

    #[DataProvider('doubleBacktickQuotesInTheChange')]
    public function testAFindingWithAPaddedDoubleBacktickQuoteInTheChangeIsKept(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function absentPaddedDoubleBacktickQuotes(): iterable
    {
        yield 'padded on both sides' => ['`` grepName `` is not defined.'];
        yield 'padded by line ends' => ["``\ngrepName\n`` is not defined."];
        yield 'after a lone pair' => ['An empty `` literal, then `` grepName `` is not defined.'];
    }

    #[DataProvider('absentPaddedDoubleBacktickQuotes')]
    public function testAnAbsentPaddedDoubleBacktickQuoteIsDropped(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAPaddedDoubleBacktickQuoteNeverRunsAcrossABlankLine(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding("`` customerName\n\nhere `` and `grepName` is not defined."), self::CHANGE);

        self::assertNotNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function singleBacktickQuotesInTheChangeWithWhiteSpaceAtAnEdge(): iterable
    {
        yield 'padded on both sides' => ['Pass ` customerName ` through `escapeShellArg` first.'];
        yield 'white space after the opening backtick only' => ['Pass ` customerName` through `escapeShellArg` first.'];
        yield 'white space before the closing backtick only' => ['Pass `customerName ` through `escapeShellArg` first.'];
        yield 'padded, after a lone backtick' => ['The ` character: ` customerName ` goes through `escapeShellArg` first.'];
        yield 'padded by line ends' => ["Pass `\ncustomerName\n` through `escapeShellArg` first."];
    }

    #[DataProvider('singleBacktickQuotesInTheChangeWithWhiteSpaceAtAnEdge')]
    public function testAFindingWithASingleBacktickQuoteInTheChangeIsKeptWhenWhiteSpaceIsAtAnEdgeOfTheQuote(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotesInTheChangeBetweenOtherBacktickRuns(): iterable
    {
        yield 'three backticks within a line' => ['Use ```customerName``` and `grepName`.'];
        yield 'runs of unequal length' => ['`customerName`` is odd; `grepName` is not defined.'];
        yield 'a single backtick inside a padded double-backtick quote' => ['`` customerName ` `` is odd; `grepName` is not defined.'];
    }

    #[DataProvider('quotesInTheChangeBetweenOtherBacktickRuns')]
    public function testAFindingIsKeptWhenAQuoteInTheChangeSitsBetweenBacktickRunsOfAnotherLength(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function doubleBacktickQuotesInTheChangeHoldingASingleBacktickQuote(): iterable
    {
        yield 'padded, the single-backtick quote last' => ['The command `` customerName + `suffix` `` needs escaping with `escapeShellArg`.'];
        yield 'padded, the single-backtick quote first' => ['The command `` `suffix` + customerName `` needs escaping with `escapeShellArg`.'];
        yield 'not padded at the opening pair' => ['The command ``customerName + `suffix` `` needs escaping with `escapeShellArg`.'];
    }

    #[DataProvider('doubleBacktickQuotesInTheChangeHoldingASingleBacktickQuote')]
    public function testAFindingIsKeptWhenADoubleBacktickQuoteInTheChangeHoldsASingleBacktickQuote(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function longerBacktickQuotesInTheChangeHoldingShorterOnes(): iterable
    {
        yield 'three backticks holding a single-backtick quote' => ['The command ```customerName + `suffix` ``` needs escaping with `escapeShellArg`.'];
        yield 'three backticks holding a double-backtick quote' => ['The command ``` ``suffix`` + customerName ``` needs escaping with `escapeShellArg`.'];
        yield 'four backticks holding a three-backtick run' => ['The command ```` customerName + ```suffix``` ```` needs escaping with `escapeShellArg`.'];
    }

    #[DataProvider('longerBacktickQuotesInTheChangeHoldingShorterOnes')]
    public function testAFindingIsKeptWhenALongerBacktickQuoteInTheChangeHoldsShorterBacktickRuns(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAnAbsentThreeBacktickQuoteHoldingASingleBacktickQuoteIsDropped(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('The command ```grepName + `suffix` ``` is not defined.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAnAbsentDoubleBacktickQuoteHoldingASingleBacktickQuoteIsDropped(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('The command `` grepName + `suffix` `` is not defined.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spansThatMayBeProse(): iterable
    {
        yield 'prose between two lone backticks' => ['The ` character opens the literal and another ` closes it.'];
        yield 'an absent padded name' => ['` grepName ` is not defined.'];
        yield 'three backticks within a line' => ['Use ```escapeShellArg(name)``` instead.'];
    }

    #[DataProvider('spansThatMayBeProse')]
    public function testASpanThatMayBeProseBetweenLoneBackticksNeverDropsAFinding(string $text): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertNull($reason);
    }

    public function testAnAbsentQuoteIsDroppedBesideProseBetweenTwoLoneBackticksThatIsNotInTheChange(): void
    {
        $text = 'The ` character opens it and another ` closes it: `grepName` is not defined.';

        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertSame('none of its 1 quoted code fragments is in the change', $reason);
    }

    public function testProseBetweenTwoQuotesNeverKeepsAFinding(): void
    {
        $reason = new QuotesInChange()->reasonToDrop($this->finding('`grepName` or customerName or `escapeShellArg` is not defined.'), self::CHANGE);

        self::assertNotNull($reason);
    }

    public function testAStrayBacktickBeforeARealQuoteCountsOnlyTheRealQuote(): void
    {
        $quotes = new QuotesInChange()->countedQuotes('A stray ` appears before `ghostName`.');

        self::assertSame(['ghostName'], $quotes);
    }

    public function testAQuoteInsideAFencedSuggestedFixIsNotCounted(): void
    {
        $text = "`ghostName` is not defined. Suggested fix:\n```js\nconst fixName = `fixedName`;\n```";

        $quotes = new QuotesInChange()->countedQuotes($text);

        self::assertSame(['ghostName'], $quotes);
    }

    public function testFileNamesAndNumbersAreNotCounted(): void
    {
        $quotes = new QuotesInChange()->countedQuotes('`src/orders.js` calls `ghostName` at line `42` of `orders.log`.');

        self::assertSame(['ghostName'], $quotes);
    }

    public function testAPaddedQuoteIsCountedTrimmed(): void
    {
        $quotes = new QuotesInChange()->countedQuotes('Wrap it as `` ghostName `` here.');

        self::assertSame(['ghostName'], $quotes);
    }

    public function testAnUnsureQuoteIsNotCounted(): void
    {
        $quotes = new QuotesInChange()->countedQuotes('Uses ` leftName` here and `ghostName` there.');

        self::assertSame(['ghostName'], $quotes);
    }

    public function testTheCountedQuotesAreAsManyAsTheDropReasonNames(): void
    {
        $text = "`ghostName` calls `otherGhost(1)` from `src/x.php`, twice: `ghostName`.\n```\n`fixName`\n```";

        $quotes = new QuotesInChange()->countedQuotes($text);
        $reason = new QuotesInChange()->reasonToDrop($this->finding($text), self::CHANGE);

        self::assertSame(['ghostName', 'otherGhost(1)', 'ghostName'], $quotes);
        self::assertSame(sprintf('none of its %d quoted code fragments is in the change', count($quotes)), $reason);
    }

    private function finding(string $text, ?string $symbol = null): Finding
    {
        return new Finding('F1', Severity::P1, 'src/orders.js', 2, $symbol, $text, FixableBy::Builder, false, null, null);
    }
}
