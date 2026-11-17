<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\FindingText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingText::class)]
final class FindingTextTest extends TestCase
{
    public function testABacktickSpanOfAFindingBecomesACodeSpanWithoutTheBackticks(): void
    {
        $text = new FindingText('Call `Lock::acquire()` before `write()`.');

        self::assertSame(
            [['text' => 'Call ', 'code' => false], ['text' => 'Lock::acquire()', 'code' => true], ['text' => ' before ', 'code' => false], ['text' => 'write()', 'code' => true], ['text' => '.', 'code' => false]],
            $text->spans(),
        );
    }

    public function testADoubleBacktickSpanHoldingABacktickBecomesOneCodeSpan(): void
    {
        $text = new FindingText('Quote ``a`b`` here.');

        self::assertSame([['text' => 'Quote ', 'code' => false], ['text' => 'a`b', 'code' => true], ['text' => ' here.', 'code' => false]], $text->spans());
    }

    public function testAFindingThatStartsAndEndsWithCodeHasNoEmptyPlainSpan(): void
    {
        $text = new FindingText('`a`');

        self::assertSame([['text' => 'a', 'code' => true]], $text->spans());
    }

    public function testAnUnmatchedBacktickMakesTheWordAfterItCode(): void
    {
        $text = new FindingText('Call `write() before returning.');

        self::assertSame([['text' => 'Call ', 'code' => false], ['text' => 'write()', 'code' => true], ['text' => ' before returning.', 'code' => false]], $text->spans());
    }

    public function testAnUnmatchedBacktickWithoutAWordAfterItIsDropped(): void
    {
        $text = new FindingText('The ` key is unused.');

        self::assertSame([['text' => 'The  key is unused.', 'code' => false]], $text->spans());
    }

    public function testBacktickRunsOfDifferentLengthsLeaveNoBacktick(): void
    {
        $text = new FindingText('See ``a` here.');

        self::assertSame([['text' => 'See ', 'code' => false], ['text' => 'a', 'code' => true], ['text' => ' here.', 'code' => false]], $text->spans());
    }

    public function testAnEmptyTextHasNoSpans(): void
    {
        self::assertSame([], new FindingText('')->spans());
    }
}
