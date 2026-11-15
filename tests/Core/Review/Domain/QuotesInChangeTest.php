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
        DIFF;

    public function testAFindingQuotingANameAbsentFromTheChangeIsDroppedWithItsReason(): void
    {
        $check = $this->checked($this->finding('F1', '`grepName` is not defined.'));

        self::assertSame([], $check->kept);
        self::assertEquals([new DroppedFinding('F1', 'It quotes `grepName`, which is in neither the change nor the files it touches.')], $check->dropped);
    }

    public function testAFindingWhoseQuotesAreInTheChangePassesThroughUnchanged(): void
    {
        $finding = $this->finding('F1', 'The name in `execSync(\'grep \' + customerName)` reaches a shell.');

        self::assertSame([$finding], $this->checked($finding)->kept);
    }

    public function testAFindingWithoutAQuoteIsKeptForTheJudge(): void
    {
        $finding = $this->finding('F1', 'The customer name reaches a shell.');

        $check = $this->checked($finding);

        self::assertSame([[$finding], []], [$check->kept, $check->dropped]);
    }

    public function testASymbolAbsentFromTheChangeDropsTheFinding(): void
    {
        $finding = new Finding('F1', Severity::P1, 'src/report.js', 2, 'grepName', 'Undefined.', FixableBy::Builder, false, null, null);

        self::assertEquals([new DroppedFinding('F1', 'It quotes `grepName`, which is in neither the change nor the files it touches.')], $this->checked($finding)->dropped);
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

    public function testOneAbsentNameAmongPresentOnesDropsTheFindingAndNamesOnlyTheAbsentOnes(): void
    {
        $check = $this->checked($this->finding('F1', '`customerName` is passed to `grepName` and `fetchAll`.'));

        self::assertSame('It quotes `grepName`, `fetchAll`, which are in neither the change nor the files it touches.', $check->dropped[0]->reason ?? null);
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

    public function testAQuoteOfOnlyNumbersAndSignsIsKept(): void
    {
        $finding = $this->finding('F1', 'The limit `>= 10` is off by one.');

        self::assertSame([$finding], $this->checked($finding)->kept);
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
