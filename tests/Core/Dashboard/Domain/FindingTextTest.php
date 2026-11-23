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

    public function testEmphasisClosedRightBeforeADashIsShownWithoutTheMarks(): void
    {
        foreach ([
            'The **read**-only mode still writes to disk.' => 'The read-only mode still writes to disk.',
            'This is *not*-as-claimed safe.' => 'This is not-as-claimed safe.',
            "The check is **wrong**\u{2014}it never runs." => "The check is wrong\u{2014}it never runs.",
            "The range is **open**\u{2013}ended, _never_-closed." => "The range is open\u{2013}ended, never-closed.",
        ] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testCodeLikeTextWithADashAfterAMarkIsLeftAsWritten(): void
    {
        foreach ([
            'Use __DIR__-relative paths and __init__.py',
            '--include=*.php --exclude=*-test.php',
            'matches *.php files and test*-old',
            'x_y_ z, _private_var and _other-name',
            'Skip src/**/x and tmp/*.',
            'Copy *Test.php to tests/*-old.',
            'Copy **kwargs to dir/**-old.',
        ] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testAClosingMarkBeforePunctuationWithoutASpaceAfterItIsLeftAsWritten(): void
    {
        foreach (['The result is **wrong**,it drops errors.', 'matches *.php,*.twig only', 'Copy *Test.php,*Case.php here'] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testAClosingMarkBeforePunctuationAndASpaceLosesItsMarks(): void
    {
        self::assertSame(
            [['text' => 'The result is wrong, it drops errors.', 'code' => false]],
            new FindingText('The result is **wrong**, it drops errors.')->spans(),
        );
    }

    public function testEmphasisClosedRightBeforeAnEllipsisLosesItsMarks(): void
    {
        self::assertSame([['text' => 'Retry…', 'code' => false]], new FindingText('*Retry*…')->spans());
    }

    public function testEmphasisEndingInASlashKeepsItsMarksUnlessThePathIsCode(): void
    {
        $written = 'The matcher must **ignore paths under /tmp/**.';

        self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans());
        self::assertSame(
            [['text' => 'The matcher must ignore paths under ', 'code' => false], ['text' => '/tmp/', 'code' => true], ['text' => '.', 'code' => false]],
            new FindingText('The matcher must **ignore paths under `/tmp/`**.')->spans(),
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

    public function testAGlobStarBeforeADashStillPairsWithALaterStarInTheSameParagraph(): void
    {
        self::assertSame([['text' => 'Match -test.php and build-old.', 'code' => false]], new FindingText('Match *-test.php and build*-old.')->spans());
    }

    public function testACommaSeparatedListWhoseLastGlobEndsInAStarLosesBothStars(): void
    {
        self::assertSame([['text' => 'Copy .php,build', 'code' => false]], new FindingText('Copy *.php,build*')->spans());
    }

    public function testACommaSeparatedListInBackticksKeepsBothStars(): void
    {
        self::assertSame([['text' => 'Copy ', 'code' => false], ['text' => '*.php,build*', 'code' => true]], new FindingText('Copy `*.php,build*`')->spans());
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

    public function testAStarBeforeADotAndAStarAfterItAreBothKept(): void
    {
        foreach (['Copy *.* here.', 'match *.* and *.php'] as $written) {
            self::assertSame([['text' => $written, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testEmphasisNestedOneLevelDeepLosesAllItsMarks(): void
    {
        foreach ([
            'say *_both_* now' => 'say both now',
            'say _*both*_ now' => 'say both now',
            'say *outer _inner_ words* now' => 'say outer inner words now',
            'say **outer *inner* words** now' => 'say outer inner words now',
            'say __outer *inner* words__ now' => 'say outer inner words now',
        ] as $written => $shown) {
            self::assertSame([['text' => $shown, 'code' => false]], new FindingText($written)->spans(), $written);
        }
    }

    public function testStarsOnEitherSideOfACodeSpanHoldingABlankLineNeverPair(): void
    {
        self::assertSame(
            [['text' => 'Copy *test.php. Example: ', 'code' => false], ['text' => "a\n\nb", 'code' => true], ['text' => '. Keep build*.', 'code' => false]],
            new FindingText("Copy *test.php. Example: `a\n\nb`. Keep build*.")->spans(),
        );
    }

    public function testStarsOnEitherSideOfACodeSpanWithABlankLineAtItsEdgeNeverPair(): void
    {
        foreach (["a\n\n" => 'a', "\n\na" => 'a'] as $code => $shown) {
            self::assertSame(
                [['text' => 'Copy *test.php. Example: ', 'code' => false], ['text' => $shown, 'code' => true], ['text' => '. Keep build*.', 'code' => false]],
                new FindingText('Copy *test.php. Example: `'.$code.'`. Keep build*.')->spans(),
            );
        }
    }

    public function testStarsOnEitherSideOfACodeSpanHoldingUnicodeParagraphSeparatorsNeverPair(): void
    {
        foreach (["a\u{2028}\u{2028}b", "a\u{2029}\u{2029}b"] as $code) {
            self::assertSame(
                [['text' => 'Copy *test.php. Example: ', 'code' => false], ['text' => $code, 'code' => true], ['text' => '. Keep build*.', 'code' => false]],
                new FindingText('Copy *test.php. Example: `'.$code.'`. Keep build*.')->spans(),
            );
        }
    }

    public function testEmphasisAroundACodeSpanWithoutABlankLineStillLosesItsMarks(): void
    {
        self::assertSame(
            [['text' => 'say ', 'code' => false], ['text' => "a\nb", 'code' => true], ['text' => ' here now', 'code' => false]],
            new FindingText("say *`a\nb` here* now")->spans(),
        );
    }

    public function testAMarkTouchingACodeSpanFromOutsideLosesItsMarks(): void
    {
        self::assertSame([['text' => 'foo', 'code' => true], ['text' => 'bar now', 'code' => false]], new FindingText('`foo`*bar* now')->spans());
        self::assertSame([['text' => 'see x', 'code' => false], ['text' => 'y', 'code' => true]], new FindingText('see **x**`y`')->spans());
    }

    public function testATextWhoseVisibleCharactersFitTheLimitIsNotCutForItsMarks(): void
    {
        $written = 'Update **'.str_repeat('x', 152).'**.';

        self::assertSame([['text' => 'Update '.str_repeat('x', 152).'.', 'code' => false]], new FindingText($written)->spansCutTo(160));
    }

    public function testATextOneCharacterOverTheLimitIsCutToTheLimitAndEndsWithAnEllipsis(): void
    {
        self::assertSame([['text' => str_repeat('a', 159).'…', 'code' => false]], new FindingText(str_repeat('a', 161))->spansCutTo(160));
        self::assertSame([['text' => str_repeat('a', 160), 'code' => false]], new FindingText(str_repeat('a', 160))->spansCutTo(160));
    }

    public function testACutAtAnyOffsetAroundAClosingMarkShowsNoMark(): void
    {
        foreach (['**', '__', '*'] as $mark) {
            foreach (range(144, 152) as $length) {
                $shown = 'Update the '.str_repeat('x', $length).' before shipping.';

                $cut = new FindingText('Update '.$mark.'the '.str_repeat('x', $length).$mark.' before shipping.')->spansCutTo(160);

                self::assertSame([['text' => rtrim(mb_substr($shown, 0, 159)).'…', 'code' => false]], $cut, $mark.' around '.$length);
            }
        }
    }

    public function testACutInsideMultiwordEmphasisShowsNoMark(): void
    {
        foreach (['*', '_', '**'] as $mark) {
            $shown = 'Update '.str_repeat('word ', 40).'end now.';

            $cut = new FindingText('Update '.$mark.str_repeat('word ', 40).'end'.$mark.' now.')->spansCutTo(160);

            self::assertSame([['text' => rtrim(mb_substr($shown, 0, 159)).'…', 'code' => false]], $cut, $mark);
        }
    }

    public function testACutJustAfterAnOpeningDoubleMarkShowsNoMark(): void
    {
        $cut = new FindingText(str_repeat('a', 158).' **bold words** and more')->spansCutTo(160);

        self::assertSame([['text' => str_repeat('a', 158).'…', 'code' => false]], $cut);
    }

    public function testACutGlobKeepsItsStar(): void
    {
        $written = str_repeat('a ', 75).'skip *Test.php files';

        self::assertSame([['text' => str_repeat('a ', 75).'skip *Tes…', 'code' => false]], new FindingText($written)->spansCutTo(160));
    }

    public function testAGlobBeforeTheCutKeepsItsStar(): void
    {
        $written = 'Copy *Test.php '.str_repeat('files ', 30);

        self::assertSame([['text' => rtrim(mb_substr($written, 0, 159)).'…', 'code' => false]], new FindingText($written)->spansCutTo(160));
    }

    public function testACutInsideACodeSpanKeepsTheCodeUpToTheCut(): void
    {
        $cut = new FindingText(str_repeat('a', 150).' `'.str_repeat('b', 20).'` done')->spansCutTo(160);

        self::assertSame([['text' => str_repeat('a', 150).' ', 'code' => false], ['text' => str_repeat('b', 8), 'code' => true], ['text' => '…', 'code' => false]], $cut);
    }

    public function testACutOfMultibyteCharactersCountsCharacters(): void
    {
        $cut = new FindingText('**'.str_repeat('é', 400).'**')->spansCutTo(160);

        self::assertSame([['text' => str_repeat('é', 159).'…', 'code' => false]], $cut);
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

        // 3000 ms because the scan takes a few ms, and the bound only catches the old quadratic seconds-long case without failing on a loaded runner.
        self::assertLessThan(3000, (hrtime(true) - $start) / 1e6);
    }

    public function testAnEmptyTextHasNoSpans(): void
    {
        self::assertSame([], new FindingText('')->spans());
    }
}
