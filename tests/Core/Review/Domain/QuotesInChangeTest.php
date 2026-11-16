<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\QuoteCheck;
use Intakt\Core\Review\Domain\QuotesInChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuotesInChange::class)]
#[CoversClass(QuoteCheck::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(Finding::class)]
final class QuotesInChangeTest extends TestCase
{
    private const string CHANGE = <<<'DIFF'
        diff --git a/src/report.js b/src/report.js
        +export function orderLog(customerName) {
        +  return execSync('grep ' + customerName + ' orders.log').toString();
        -const legacyName = 1;
        +const limit = 100;
        +class Order { total() { return Shop\Money.zero; } }
        DIFF;

    public function testAFindingQuotingANameAbsentFromTheChangeIsDroppedWithItsReason(): void
    {
        $check = $this->checked($this->finding('F1', '`grepName` is not defined.'));

        self::assertSame([], $check->kept);
        self::assertEquals([new DroppedFinding('F1', 'None of its quotes is in the change or the files it touches: `grepName`.')], $check->dropped);
    }

    public function testAFindingWhoseQuotesAreInTheChangePassesThroughUnchanged(): void
    {
        $finding = $this->finding('F1', 'The name in `execSync(\'grep \' + customerName + \' orders.log\')` reaches a shell.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testAFindingWithoutAQuoteIsKeptForTheJudge(): void
    {
        $finding = $this->finding('F1', 'The customer name reaches a shell.');

        $check = $this->checked($finding);

        self::assertSame([[$finding], []], [$check->kept, $check->dropped]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realFindingsQuotingCodeTheChangeDoesNotHold(): iterable
    {
        yield 'the name of the fix' => ['`customerName` reaches a shell; use `execFileSync` instead.'];
        yield 'a language word' => ['`orderLog` returns `undefined` when the log is empty.'];
        yield 'a doc to update' => ['`orderLog` is not described in `docs/user/README.md`.'];
        yield 'a test file to add' => ['No test covers `orderLog`; add `tests/report.test.js`.'];
    }

    #[DataProvider('realFindingsQuotingCodeTheChangeDoesNotHold')]
    public function testAFindingWithOneQuoteInTheChangeIsKeptAlthoughItQuotesOtherCode(string $text): void
    {
        self::assertSame([], $this->checked($this->finding('F1', $text))->dropped);
    }

    public function testAFindingThatOnlyQuotesAPathIsLeftToTheJudge(): void
    {
        $finding = $this->finding('F1', 'The change is not described in `docs/user/README.md`.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testAFindingThatOnlyQuotesSignsIsLeftToTheJudge(): void
    {
        $finding = new Finding('F1', Severity::P1, 'src/report.js', 2, '...', 'A `;` is missing.', FixableBy::Builder, false, null, null);

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fragmentsTheChangeDoesNotHold(): iterable
    {
        yield 'names of the change joined into a call it never makes' => ['`customerName.execSync()` throws.'];
        yield 'a comparison without names' => ['The limit is `>= 10`.'];
        yield 'a number that only starts a number of the change' => ['The limit `10` is too low.'];
    }

    #[DataProvider('fragmentsTheChangeDoesNotHold')]
    public function testAFragmentTheChangeDoesNotHoldAsAWholeDropsTheFinding(string $text): void
    {
        self::assertSame('F1', $this->checked($this->finding('F1', $text))->dropped[0]->id ?? null);
    }

    public function testAFragmentSpacedOtherwiseThanInTheChangeIsInIt(): void
    {
        $finding = $this->finding('F1', 'The name in `return  execSync(\'grep \'  +  customerName` reaches a shell.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testACallQuotedWithEmptyParenthesesIsInTheChangeWhenTheNameIs(): void
    {
        $finding = $this->finding('F1', '`orderLog()` runs a shell.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testAMemberReferenceIsInTheChangeWhenEachOfItsNamesIs(): void
    {
        $finding = $this->finding('F1', '`Order::total()` and `\Shop\Money` return nothing.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testAMemberReferenceToAnAbsentNameDropsTheFinding(): void
    {
        self::assertCount(1, $this->checked($this->finding('F1', '`Order::grepName()` is undefined.'))->dropped);
    }

    public function testASymbolAbsentFromTheChangeDropsTheFinding(): void
    {
        $finding = new Finding('F1', Severity::P1, 'src/report.js', 2, 'grepName', 'Undefined.', FixableBy::Builder, false, null, null);

        self::assertEquals([new DroppedFinding('F1', 'None of its quotes is in the change or the files it touches: `grepName`.')], $this->checked($finding)->dropped);
    }

    public function testASymbolInTheChangeKeepsTheFinding(): void
    {
        $finding = new Finding('F1', Severity::P1, 'src/report.js', 2, 'orderLog()', 'Runs a shell.', FixableBy::Builder, false, null, null);

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testANameOnlyOnARemovedLineCountsAsInTheChange(): void
    {
        $finding = $this->finding('F1', 'Removing `legacyName` breaks the export.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testANameThatOnlyContainsANameOfTheChangeIsNotInIt(): void
    {
        $check = $this->checked($this->finding('F1', '`customer` is unused.'));

        self::assertSame('F1', $check->dropped[0]->id ?? null);
    }

    public function testTheReasonNamesEveryQuoteOfADroppedFinding(): void
    {
        $check = $this->checked($this->finding('F1', '`grepName` is passed to `fetchAll`.'));

        self::assertSame('None of its quotes is in the change or the files it touches: `grepName`, `fetchAll`.', $check->dropped[0]->reason ?? null);
    }

    public function testACodeBlockInAFindingIsNotReadAsAQuote(): void
    {
        $finding = $this->finding('F1', "Pass the name as an argument:\n```js\nexecFileSync('grep', [customerName]);\n```\nso `customerName` never reaches a shell.");

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testAQuoteInDoubleBackticksIsChecked(): void
    {
        $check = $this->checked($this->finding('F1', 'The ``grepName`` call fails.'));

        self::assertCount(1, $check->dropped);
    }

    public function testADroppedFindingLeavesTheOthersInTheirOrder(): void
    {
        $first = $this->finding('F1', 'Uses `customerName` raw.');
        $third = $this->finding('F3', 'No quote here.');

        $check = new QuotesInChange()->check([$first, $this->finding('F2', '`grepName` is undefined.'), $third], self::CHANGE);

        self::assertSame([$first, $third], $check->kept);
    }

    private function checked(Finding $finding): QuoteCheck
    {
        return new QuotesInChange()->check([$finding], self::CHANGE);
    }

    private function finding(string $id, string $text): Finding
    {
        return new Finding($id, Severity::P1, 'src/report.js', 2, null, $text, FixableBy::Builder, false, null, null);
    }
}
