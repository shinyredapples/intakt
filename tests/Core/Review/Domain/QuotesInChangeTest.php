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

    private function finding(string $text, ?string $symbol = null): Finding
    {
        return new Finding('F1', Severity::P1, 'src/orders.js', 2, $symbol, $text, FixableBy::Builder, false, null, null);
    }
}
