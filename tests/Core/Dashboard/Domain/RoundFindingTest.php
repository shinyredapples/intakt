<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\RoundFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundFinding::class)]
final class RoundFindingTest extends TestCase
{
    public function testABacktickSpanOfAFindingBecomesACodeSpanWithoutTheBackticks(): void
    {
        $finding = new RoundFinding('F1', 'P1', 'Call `Lock::acquire()` before `write()`.');

        self::assertSame(
            [['text' => 'Call ', 'code' => false], ['text' => 'Lock::acquire()', 'code' => true], ['text' => ' before ', 'code' => false], ['text' => 'write()', 'code' => true], ['text' => '.', 'code' => false]],
            $finding->textSpans(),
        );
    }

    public function testADoubleBacktickSpanHoldingABacktickBecomesOneCodeSpan(): void
    {
        $finding = new RoundFinding('F1', 'P1', 'Quote ``a`b`` here.');

        self::assertSame([['text' => 'Quote ', 'code' => false], ['text' => 'a`b', 'code' => true], ['text' => ' here.', 'code' => false]], $finding->textSpans());
    }

    public function testAFindingThatStartsAndEndsWithCodeHasNoEmptyPlainSpan(): void
    {
        $finding = new RoundFinding('F1', 'P1', '`a`');

        self::assertSame([['text' => 'a', 'code' => true]], $finding->textSpans());
    }

    public function testAnUnmatchedBacktickStaysPlainText(): void
    {
        $finding = new RoundFinding('F1', 'P1', 'The ` key is unused.');

        self::assertSame([['text' => 'The ` key is unused.', 'code' => false]], $finding->textSpans());
    }

    public function testAFindingWithoutTextHasNoSpans(): void
    {
        self::assertSame([], new RoundFinding('F1', null, null)->textSpans());
    }

    public function testAFindingsPlaceReadsFileColonLine(): void
    {
        self::assertSame('src/A.php:7', new RoundFinding('F1', 'P1', 'A.', file: 'src/A.php', line: 7)->place());
    }

    public function testAFindingWithAFileButNoLineNamesTheFileAlone(): void
    {
        self::assertSame('src/A.php', new RoundFinding('F1', 'P1', 'A.', file: 'src/A.php')->place());
    }

    public function testAFindingWithoutAFileHasNoPlace(): void
    {
        self::assertNull(new RoundFinding('F1', 'P1', 'A.', line: 7)->place());
    }
}
