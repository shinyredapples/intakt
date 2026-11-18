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

    public function testDoubleAsterisksAroundWordsAreShownWithoutTheMarks(): void
    {
        $text = new FindingText('the amount is **greater than** 100');

        self::assertSame([['text' => 'the amount is greater than 100', 'code' => false]], $text->spans());
    }

    public function testSingleAsterisksAroundWordsAreShownWithoutTheMarks(): void
    {
        $text = new FindingText('states *what* should happen, not *why*.');

        self::assertSame([['text' => 'states what should happen, not why.', 'code' => false]], $text->spans());
    }

    public function testUnderscoreEmphasisIsShownWithoutTheMarks(): void
    {
        $text = new FindingText('say _why_ and __not how__');

        self::assertSame([['text' => 'say why and not how', 'code' => false]], $text->spans());
    }

    public function testTripleAsterisksAroundWordsAreShownWithoutTheMarks(): void
    {
        $text = new FindingText('(***never***)');

        self::assertSame([['text' => '(never)', 'code' => false]], $text->spans());
    }

    public function testEmphasisAroundACodeSpanIsDroppedAndTheCodeKept(): void
    {
        $text = new FindingText('**`write()`** is never called');

        self::assertSame([['text' => 'write()', 'code' => true], ['text' => ' is never called', 'code' => false]], $text->spans());
    }

    public function testEmphasisMarksInsideACodeSpanAreKept(): void
    {
        $text = new FindingText('Match `src/**/*.php` and `*a*`.');

        self::assertSame(
            [['text' => 'Match ', 'code' => false], ['text' => 'src/**/*.php', 'code' => true], ['text' => ' and ', 'code' => false], ['text' => '*a*', 'code' => true], ['text' => '.', 'code' => false]],
            $text->spans(),
        );
    }

    public function testMarksInsideWordsArithmeticAndPathsAreLeftAsWritten(): void
    {
        $written = 'Keep max_retry_count and __construct, 2 * 3 * 4, a*b*c, a lone * here and src/**/x/**/y.';

        self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans());
    }

    public function testDunderNamesKeepTheirMarks(): void
    {
        foreach ([
            'Use __DIR__ here, not __FILE__ or __CLASS__, and __init__.py or __invoke__.',
            'call __init_subclass__ here',
            'the __webpack_require__ global',
            'override __get_state__ too',
        ] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testSeparateMarksNeverPairAcrossEachOther(): void
    {
        foreach ([
            'matches (*.php|*.twig) only',
            'files "*.php" and "*.twig" are skipped',
            'then *x*/*y* here',
            'x_y_ z, _private_var and _other',
        ] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testGlobsPairingAcrossEachOtherKeepTheirStars(): void
    {
        foreach ([
            'matches *.php or src/*',
            'skip *.php and vendor/* here',
            'globs **/a and b/** stay',
        ] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testEmphasisAroundADotWordBeforePunctuationOrInCurlyQuotesLosesItsMarks(): void
    {
        foreach ([
            'Fix **now**, then *retry*...' => 'Fix now, then retry...',
            'Check **.env** first.' => 'Check .env first.',
            '“*Retry*” first.' => '“Retry” first.',
            'it ‘_failed_’ twice…' => 'it ‘failed’ twice…',
        ] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testLongEmphasisLosesItsMarks(): void
    {
        $long = str_repeat('word ', 60).'end';

        self::assertSame([['text' => $long, 'code' => false]], new FindingText('**'.$long.'**')->spans());
    }

    public function testAGlobAfterADotPairsWithNoStarLaterInTheSentence(): void
    {
        $written = 'matches *.php files and test*';

        self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans());
    }

    public function testStarsInSeparateParagraphsNeverPair(): void
    {
        $written = "Copy *test.php.\n\nKeep files named build*.";

        self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans());
    }

    public function testAnOpeningMarkOfATitleCutShortLosesItsMark(): void
    {
        self::assertSame([['text' => 'Update the word…', 'code' => false]], new FindingText('Update **the word…')->spans());
    }

    public function testAnEllipsisClosesNoMarkBeforeASpaceOrSlash(): void
    {
        foreach (['skip src/*…', 'a lone * …'] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testEmphasisRunningOverALineBreakLosesItsMarks(): void
    {
        self::assertSame([['text' => "multi\nline", 'code' => false]], new FindingText("**multi\nline**")->spans());
    }

    public function testMarksThatCloseNothingAreLeftAsWritten(): void
    {
        $written = 'An unclosed **mark, a spaced ** pair ** and inside**words.';

        self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans());
    }

    public function testManyUnclosedMarksAreReadInLinearTime(): void
    {
        $start = hrtime(true);
        new FindingText(str_repeat(' *a', 20000).' end')->spans();

        self::assertLessThan(500, (hrtime(true) - $start) / 1e6);
    }

    public function testAnEmptyTextHasNoSpans(): void
    {
        self::assertSame([], new FindingText('')->spans());
    }
}
