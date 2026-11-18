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

    public function testStarsInParagraphsSeparatedByAnyLineEndingNeverPair(): void
    {
        foreach (["Copy *test.php.\r\n\r\nKeep build*.", "Copy *test.php.\r\rKeep build*.", "Copy *test.php.\n \t\nKeep build*."] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), json_encode($written, JSON_THROW_ON_ERROR));
        }
    }

    public function testDoubleMarksAroundSeveralWordsStartingWithADotLoseTheirMarks(): void
    {
        foreach ([
            'Check **.env stays private** before publishing.' => 'Check .env stays private before publishing.',
            'Keep __.gitignore and friends__ as is' => 'Keep .gitignore and friends as is',
        ] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testASingleMarkBeforeADotNeverPairsAroundSeveralWords(): void
    {
        $written = 'Check *.env files and backups*.';

        self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans());
    }

    public function testAnOpeningMarkOfATitleCutShortLosesItsMark(): void
    {
        foreach ([
            'Update **the word…' => 'Update the word…',
            'Update __the word…' => 'Update the word…',
            'Update *word…' => 'Update word…',
            'Update _word…' => 'Update word…',
            'Fix **now** and **the rest…' => 'Fix now and the rest…',
            'Update ***word…' => 'Update word…',
        ] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written, true)->spans(), $written);
        }
    }

    public function testATitleCutBetweenTheTwoClosingMarksLosesTheOneThatIsLeft(): void
    {
        foreach ([
            'Update **the word*…' => 'Update the word…',
            'Update __the word_…' => 'Update the word…',
            'Update **word*…' => 'Update word…',
        ] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written, true)->spans(), $written);
        }
    }

    public function testATitleCutRightAfterItsClosingMarkLosesBothMarks(): void
    {
        foreach (['Update **the word**…' => 'Update the word…', 'Update *word*…' => 'Update word…', 'Update __the word__…' => 'Update the word…'] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written, true)->spans(), $written);
        }
    }

    public function testAWildcardInATitleCutShortKeepsItsStar(): void
    {
        foreach (['Copy *test files…', 'globs *.php…', 'skip src/*…', 'a lone * …', 'Copy *.php and *.twig files…', 'Match **/kwargs…'] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written, true)->spans(), $written);
        }
    }

    public function testAnEllipsisTheReviewerWroteClosesNoMark(): void
    {
        foreach (['Copy *.php…', 'Copy *test.php…', 'Match **kwargs…', 'Use _private and more…', 'Copy *.php and *.twig…', 'Copy *test files…', 'Update **the word*…', 'Update __the word…'] as $written) {
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
