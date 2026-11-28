<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ComposedTraitMethod;
use Intakt\Gates\PhpStan\PhpUnitTestMethod;
use Intakt\Gates\PhpStan\SentenceTestName;
use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Intakt\Gates\PhpStan\TestProviderCollector;
use Intakt\Gates\PhpStan\TraitAliasCollector;
use Intakt\Gates\PhpStan\TraitComposition;
use Intakt\Gates\PhpStan\TraitMethodDeclaration;
use Intakt\Gates\PhpStan\TraitMethodLineCollector;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPStan\Parser\Parser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodSentenceRule>
 */
#[CoversClass(TestMethodSentenceRule::class)]
#[CoversClass(TraitAliasCollector::class)]
#[CoversClass(TraitMethodLineCollector::class)]
#[CoversClass(TraitMethodDeclaration::class)]
#[CoversClass(TraitComposition::class)]
#[CoversClass(ComposedTraitMethod::class)]
#[CoversClass(TestMethodCollector::class)]
#[CoversClass(SentenceTestName::class)]
#[CoversClass(TestProviderCollector::class)]
#[CoversClass(PhpUnitTestMethod::class)]
final class TestMethodTraitAliasTest extends PhpStanRuleTestCase
{
    private const string FINDING = ' is not named as a sentence: name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().';

    public function testAShortAliasOfATraitUnderSourceIsReportedAtTheAliasInTheTestClass(): void
    {
        $this->assertRuleRejects('test-method-alias-of-src-trait', 'tests/VerifyTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\VerifyTest::testVerify()'.self::FINDING);
    }

    public function testAShortTestMethodOfATraitUnderSourceThatStillRunsBesideItsAliasIsReportedAtTheAlias(): void
    {
        self::assertSame(
            'tests/VerifyTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\VerifyTest::testVerify()'.self::FINDING."\n"
            .'tests/VerifyTest.php:13 intakt.testMethodSentence Test method Intakt\Sample\VerifyTest::testRuns()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-of-src-trait'),
        );
    }

    public function testAShortAliasOfATraitTestMethodUnderTestsIsReportedAtTheAliasAndTheShortOriginalInTheTrait(): void
    {
        self::assertSame(
            'tests/SharedChecks.php:14 intakt.testMethodSentence Test method Intakt\Sample\SharedChecks::testRuns()'.self::FINDING."\n"
            .'tests/FirstTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\FirstTest::testGo()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-in-tests'),
        );
    }

    public function testATraitTestMethodExcludedByInsteadofIsNotReportedUnderTheNameItLost(): void
    {
        self::assertSame('tests/SecondChecks.php:9 intakt.testMethodSentence Test method Intakt\Sample\SecondChecks::testRuns()'.self::FINDING, $this->ruleFindingsOf('test-method-alias-insteadof'));
    }

    public function testOnlyAnAliasMadePublicIsReportedWhenOtherAliasesHideTheTraitTestMethod(): void
    {
        self::assertSame('tests/HiddenTest.php:14 intakt.testMethodSentence Test method Intakt\Sample\HiddenTest::testGo()'.self::FINDING, $this->ruleFindingsOf('test-method-alias-visibility'));
    }

    public function testAShortAliasInAWrapperTraitIsReportedOnceAtTheAliasInTheWrapper(): void
    {
        self::assertSame('tests/Wrapper.php:10 intakt.testMethodSentence Test method Intakt\Sample\Wrapper::testGo()'.self::FINDING, $this->ruleFindingsOf('test-method-alias-in-wrapper-trait'));
    }

    public function testAnAttributedTraitTestMethodUnderTestsIsReportedInTheTraitAndAtItsPublicAlias(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/MarkedTest.php', <<<'PHP_WRAP'
        <?php
        
        namespace Intakt\Sample;
        
        use PHPUnit\Framework\Attributes\Test;
        use PHPUnit\Framework\TestCase;
        
        trait MarkedChecks
        {
            #[Test]
            public function check(): void
            {
            }
        }
        
        final class MarkedTest extends TestCase
        {
            use MarkedChecks { check as checkAgain; }
        }
        PHP_WRAP);

        self::assertSame(
            'tests/MarkedTest.php:18 intakt.testMethodSentence Test method Intakt\Sample\MarkedTest::checkAgain()'.self::FINDING."\n"
            .'tests/MarkedTest.php:11 intakt.testMethodSentence Test method Intakt\Sample\MarkedChecks::check()'.self::FINDING,
            $this->ruleFindingsIn($sandbox),
        );
    }

    public function testOnlyTheAliasOfTheWrapperTraitThatInsteadofSelectsIsReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/PrecedenceTest.php', <<<'PHP_WRAP'
        <?php
        
        namespace Intakt\Sample;
        
        use PHPUnit\Framework\TestCase;
        
        trait LeftCheck { public function left(): void {} }
        trait RightCheck { public function right(): void {} }
        trait LeftWrapper { use LeftCheck { left as testGo; } }
        trait RightWrapper { use RightCheck { right as testGo; } }
        
        final class PrecedenceTest extends TestCase
        {
            use LeftWrapper, RightWrapper { RightWrapper::testGo insteadof LeftWrapper; }
        }
        PHP_WRAP);

        self::assertSame('tests/PrecedenceTest.php:10 intakt.testMethodSentence Test method Intakt\Sample\RightWrapper::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testATraitMethodExcludedByInsteadofIsNotReportedAtItsAliasWhenTheWinnerSharesItsLine(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/SameLineChecks.php', <<<'PHP'
            <?php

            namespace Intakt\Sample;

            trait SameLineLeft { public function testGo(): void {} } trait SameLineRight { public function testGo(): void {} }
            PHP);
        $sandbox->write('tests/SameLineTest.php', <<<'PHP_WRAP'
        <?php
        
        namespace Intakt\Sample;
        
        use PHPUnit\Framework\TestCase;
        
        final class SameLineTest extends TestCase
        {
            use SameLineLeft, SameLineRight {
                SameLineRight::testGo insteadof SameLineLeft;
                SameLineLeft::testGo as testTheLeftCheckRuns;
            }
        }
        PHP_WRAP);

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAShortAliasThatAWrapperOutsideTestsGivesATraitTestMethodUnderTestsIsReportedAtTheTestClass(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/WrappedChecks.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait WrappedChecks { public function testTheCheckRuns(): void {} }\n");
        $sandbox->write('src/SourceWrapper.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait SourceWrapper { use WrappedChecks { testTheCheckRuns as testGo; } }\n");
        $sandbox->write('tests/SourceWrappedTest.php', "<?php\n\nnamespace Intakt\\Sample;\n\nfinal class SourceWrappedTest extends \\PHPUnit\\Framework\\TestCase { use SourceWrapper; }\n");

        self::assertSame('tests/SourceWrappedTest.php:5 intakt.testMethodSentence Test method Intakt\Sample\SourceWrappedTest::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAShortTestMethodOfATraitUnderSourceAliasedTwiceIsReportedOnceAtTheFirstAlias(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/TwiceChecks.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait TwiceChecks { public function testRuns(): void {} }\n");
        $sandbox->write('tests/TwiceTest.php', <<<'PHP_WRAP'
        <?php
        
        namespace Intakt\Sample;
        
        final class TwiceTest extends \PHPUnit\Framework\TestCase
        {
            use TwiceChecks {
                testRuns as testRunsTheFirstWay;
                testRuns as testRunsTheSecondWay;
            }
        }
        PHP_WRAP);

        self::assertSame('tests/TwiceTest.php:8 intakt.testMethodSentence Test method Intakt\Sample\TwiceTest::testRuns()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAnOriginalMadePrivateBesideAnAliasWithoutModifierIsHiddenWhileTheAliasStaysPublic(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/HiddenOriginalChecks.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait HiddenOriginalChecks { public function testRuns(): void {} }\n");
        $sandbox->write('tests/HiddenOriginalTest.php', <<<'PHP_WRAP'
        <?php
        
        namespace Intakt\Sample;
        
        final class HiddenOriginalTest extends \PHPUnit\Framework\TestCase
        {
            use HiddenOriginalChecks {
                testRuns as private;
                testRuns as testRunsAgain;
            }
        }
        PHP_WRAP);

        self::assertSame('tests/HiddenOriginalTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\HiddenOriginalTest::testRunsAgain()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAShortAliasOfAWrapperTraitThatTheTestClassMakesPrivatePassesStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/PrivateWrapper.php', <<<'PHP_WRAP'
        <?php
        
        namespace Intakt\Sample;
        
        trait PrivateWrapperChecks { public function helper(): void {} }
        trait PrivateWrapper { use PrivateWrapperChecks { helper as testGo; } }
        
        final class PrivateWrapperTest extends \PHPUnit\Framework\TestCase
        {
            use PrivateWrapper { testGo as private; }
        }
        PHP_WRAP);

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testOnlyTheSelectedWrapperIsReportedWhenBothWrappersAliasTheSameMethod(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/SelectedTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait Shared { public function helper(): void {} }
        trait Left { use Shared { helper as testGo; } }
        trait Right { use Shared { helper as testGo; } }

        final class SelectedTest extends \PHPUnit\Framework\TestCase
        {
            use Left, Right { Right::testGo insteadof Left; }
        }
        PHP_WRAP);

        self::assertSame('tests/SelectedTest.php:7 intakt.testMethodSentence Test method Intakt\Sample\Right::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testOnlyTheSelectedWrapperIsReportedWhenTheAliasedMethodsShareALine(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/SameLineWrappersTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait Other { public function doFirst(): void {} public function doSecond(): void {} }
        trait WrapFirst { use Other { doFirst as testDo; } }
        trait WrapSecond { use Other { doSecond as testDo; } }

        final class SameLineWrappersTest extends \PHPUnit\Framework\TestCase
        {
            use WrapFirst, WrapSecond { WrapSecond::testDo insteadof WrapFirst; }
        }
        PHP_WRAP);

        self::assertSame('tests/SameLineWrappersTest.php:7 intakt.testMethodSentence Test method Intakt\Sample\WrapSecond::testDo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testOnlyTheWrapperThatAWrapperOfWrappersSelectsIsReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/MiddleTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait Deep { public function helper(): void {} }
        trait DeepLeft { use Deep { helper as testGo; } }
        trait DeepRight { use Deep { helper as testGo; } }
        trait Middle { use DeepLeft, DeepRight { DeepLeft::testGo insteadof DeepRight; } }

        final class MiddleTest extends \PHPUnit\Framework\TestCase
        {
            use Middle;
        }
        PHP_WRAP);

        self::assertSame('tests/MiddleTest.php:6 intakt.testMethodSentence Test method Intakt\Sample\DeepLeft::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testTheOriginalNameOfAnAliasInAWrapperThatInsteadofExcludesIsNotReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/RouteChecks.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait RouteChecks { public function testRuns(): void {} }\n");
        $sandbox->write('tests/RouteTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait RouteLeft { use RouteChecks { testRuns as testTheLeftCheckRuns; } }
        trait RouteRight { use RouteChecks; }

        final class RouteTest extends \PHPUnit\Framework\TestCase
        {
            use RouteLeft, RouteRight { RouteRight::testRuns insteadof RouteLeft; }
        }

        final class RouteLeftTest extends \PHPUnit\Framework\TestCase
        {
            use RouteLeft;
        }
        PHP_WRAP);

        self::assertSame('tests/RouteTest.php:5 intakt.testMethodSentence Test method Intakt\Sample\RouteLeft::testRuns()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAPublicAliasInAWrapperThatAlsoHidesTheOriginalIsReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/WrapHide.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait HideChecks { public function helper(): void {} }
        trait HideWrapper { use HideChecks { helper as private; helper as testGo; } }

        final class HideWrapperTest extends \PHPUnit\Framework\TestCase
        {
            use HideWrapper;
        }
        PHP_WRAP);

        self::assertSame('tests/WrapHide.php:6 intakt.testMethodSentence Test method Intakt\Sample\HideWrapper::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAWrapperAliasThatTheTestClassHidesAndRenamesInOneBlockPassesStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/HiddenAtUseTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait HiddenAtUseChecks { public function helper(): void {} }
        trait HiddenAtUseWrapper { use HiddenAtUseChecks { helper as testGo; } }

        final class HiddenAtUseTest extends \PHPUnit\Framework\TestCase
        {
            use HiddenAtUseWrapper { testGo as private; testGo as testTheCheckRuns; }
        }
        PHP_WRAP);

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAPrivateWrapperAliasThatTheTestClassMakesPublicIsReportedAtTheAliasInTheWrapper(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/WidenedTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait WidenedChecks { public function helper(): void {} }
        trait WidenedWrapper { use WidenedChecks { helper as private testNo; } }

        final class WidenedTest extends \PHPUnit\Framework\TestCase
        {
            use WidenedWrapper { testNo as public; }
        }
        PHP_WRAP);

        self::assertSame('tests/WidenedTest.php:6 intakt.testMethodSentence Test method Intakt\Sample\WidenedWrapper::testNo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAWrapperAliasThatTheTestClassRenamesAgainIsReportedOnceUnderTheWrapper(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/RenamedTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait RenamedChecks { public function helper(): void {} }
        trait RenamedWrapper { use RenamedChecks { helper as testGo; } }

        final class RenamedTest extends \PHPUnit\Framework\TestCase
        {
            use RenamedWrapper { testGo as testTheCheckRuns; }
        }
        PHP_WRAP);

        self::assertSame('tests/RenamedTest.php:6 intakt.testMethodSentence Test method Intakt\Sample\RenamedWrapper::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testATraitTestMethodAliasedInTwoUseStatementsIsReportedOnceInTheTrait(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/TwoStatementsTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait TwoStatementsChecks { public function testDo(): void {} }

        final class TwoStatementsTest extends \PHPUnit\Framework\TestCase
        {
            use TwoStatementsChecks { testDo as testTheFirstThing; }
            use TwoStatementsChecks { testDo as testTheSecondThing; }
        }
        PHP_WRAP);

        self::assertSame('tests/TwoStatementsTest.php:5 intakt.testMethodSentence Test method Intakt\Sample\TwoStatementsChecks::testDo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAStillRunOriginalOfATraitThatIsNotAnalyzedIsReportedOnTheTraitsOwnLine(): void
    {
        $sandbox = $this->emptySandbox();
        // because the gate excludes its fixtures folder, a trait there stands for a file a changed-files run leaves out
        $sandbox->write('tests/Gates/Fixtures/PartialChecks.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait PartialChecks\n{\n    public function testGo(): void {}\n}\n");
        $sandbox->write('tests/PartialTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        final class PartialTest extends \PHPUnit\Framework\TestCase
        {


            use PartialChecks { testGo as testTheCheckRuns; }
        }
        PHP_WRAP);
        // because the analysis finds a class it does not analyze only once PHP has loaded it
        require_once $sandbox->root.'/tests/Gates/Fixtures/PartialChecks.php';

        self::assertSame('tests/Gates/Fixtures/PartialChecks.php:7 intakt.testMethodSentence Test method Intakt\Sample\PartialChecks::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAShortAliasThroughAWrapperTraitThatIsNotAnalyzedIsReportedAtTheAliasInTheTestClass(): void
    {
        $sandbox = $this->emptySandbox();
        // because the gate excludes its fixtures folder, a wrapper there stands for an unchanged file a changed-files run leaves out
        $sandbox->write('tests/Gates/Fixtures/UnanalyzedWrapper.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait UnanalyzedLeaf { public function verify(): void {} }\ntrait UnanalyzedWrapper { use UnanalyzedLeaf; }\n");
        $sandbox->write('tests/WrappedTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        final class WrappedTest extends \PHPUnit\Framework\TestCase
        {
            use UnanalyzedWrapper { verify as testGo; }
        }
        PHP_WRAP);
        // because the analysis finds a class it does not analyze only once PHP has loaded it
        require_once $sandbox->root.'/tests/Gates/Fixtures/UnanalyzedWrapper.php';

        self::assertSame('tests/WrappedTest.php:7 intakt.testMethodSentence Test method Intakt\Sample\WrappedTest::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testTwoAliasUseBlocksOnOneLineAreBothComposed(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/TwoUsesTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait TwoUsesChecks { public function verify(): void {} }

        final class TwoUsesTest extends \PHPUnit\Framework\TestCase
        {
            use TwoUsesChecks { verify as testGo; } use TwoUsesChecks { verify as testTheCheckRuns; }
        }
        PHP_WRAP);

        self::assertSame('tests/TwoUsesTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\TwoUsesTest::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testATestMethodTheClassHidesDoesNotMakeAShortPublicAliasAProvider(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/HiddenConsumerTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        use PHPUnit\Framework\Attributes\DataProvider;

        trait HiddenConsumerChecks
        {
            #[DataProvider('testData')]
            public function testItUsesRows(int $row): void {}

            public static function rows(): array { return [[1]]; }
        }

        final class HiddenConsumerTest extends \PHPUnit\Framework\TestCase
        {
            use HiddenConsumerChecks {
                testItUsesRows as private;
                testItUsesRows as consume;
                rows as testData;
            }
        }
        PHP_WRAP);

        self::assertSame('tests/HiddenConsumerTest.php:20 intakt.testMethodSentence Test method Intakt\Sample\HiddenConsumerTest::testData()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testATestMethodAnotherClassHidesDoesNotMakeAShortPublicAliasAnExternalProvider(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/ExternalConsumerTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        use PHPUnit\Framework\Attributes\DataProviderExternal;

        trait ExternalRowSource
        {
            public static function rows(): array { return [[1]]; }
        }

        trait ExternalConsumerChecks
        {
            #[DataProviderExternal(ExternalTargetTest::class, 'testData')]
            public function testItUsesRows(int $row): void {}
        }

        final class ExternalTargetTest extends \PHPUnit\Framework\TestCase
        {
            use ExternalRowSource { rows as testData; }
        }

        final class ExternalConsumerTest extends \PHPUnit\Framework\TestCase
        {
            use ExternalConsumerChecks {
                testItUsesRows as private;
                testItUsesRows as consume;
            }
        }
        PHP_WRAP);

        self::assertStringContainsString('tests/ExternalConsumerTest.php:20 intakt.testMethodSentence Test method Intakt\Sample\ExternalTargetTest::testData()', $this->ruleFindingsIn($sandbox));
    }

    public function testAPublicAliasOfAHiddenTraitTestMakesTheProviderItNamesExempt(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/PublicConsumerTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        use PHPUnit\Framework\Attributes\DataProvider;

        trait PublicConsumerChecks
        {
            #[DataProvider('testData')]
            public function consume(int $row): void {}

            public static function rows(): array { return [[1]]; }
        }

        final class PublicConsumerTest extends \PHPUnit\Framework\TestCase
        {
            use PublicConsumerChecks {
                consume as private;
                consume as testItUsesRows;
                rows as testData;
            }
        }
        PHP_WRAP);

        self::assertStringNotContainsString('PublicConsumerTest::testData()', $this->ruleFindingsIn($sandbox));
    }

    public function testAStillRunOriginalThatASourceWrapperAliasedIsReportedAtTheAliasInTheTestClass(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/OriginalChecks.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        trait OriginalChecks { public function testGo(): void {} }
        trait OriginalWrapper { use OriginalChecks { testGo as testTheWrapperCheckRuns; } }
        PHP_WRAP);
        $sandbox->write('tests/OriginalCheckTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        final class OriginalCheckTest extends \PHPUnit\Framework\TestCase
        {
            use OriginalWrapper { testGo as testTheClassCheckRuns; }
        }
        PHP_WRAP);

        self::assertSame('tests/OriginalCheckTest.php:7 intakt.testMethodSentence Test method Intakt\Sample\OriginalCheckTest::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testATestMethodAnAbstractParentHidesDoesNotMakeAShortPublicAliasAnExternalProvider(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/InheritedConsumerTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        use PHPUnit\Framework\Attributes\DataProviderExternal;

        trait InheritedRowSource { public static function rows(): array { return [[1]]; } }

        final class InheritedTargetTest extends \PHPUnit\Framework\TestCase { use InheritedRowSource { rows as testData; } }

        trait InheritedConsumerChecks
        {
            #[DataProviderExternal(InheritedTargetTest::class, 'testData')]
            public function testItUsesRows(int $row): void {}
        }

        abstract class AbstractInheritedConsumer extends \PHPUnit\Framework\TestCase
        {
            use InheritedConsumerChecks { testItUsesRows as private; testItUsesRows as consume; }
        }

        final class InheritedConsumerTest extends AbstractInheritedConsumer {}
        PHP_WRAP);

        self::assertStringContainsString('tests/InheritedConsumerTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\InheritedTargetTest::testData()', $this->ruleFindingsIn($sandbox));
    }

    public function testAPublicAliasOfAWrapperThatIsNotAnalyzedStaysPublicWhenTheWrapperHidesTheOriginal(): void
    {
        $sandbox = $this->emptySandbox();
        // because the gate excludes its fixtures folder, a wrapper there stands for an unchanged file a changed-files run leaves out
        $sandbox->write('tests/Gates/Fixtures/HidingWrapper.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait HidingLeaf { public function helper(): void {} }\ntrait HidingWrapper { use HidingLeaf { helper as private; helper as verify; } }\n");
        $sandbox->write('tests/HidingWrapperTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        final class HidingWrapperTest extends \PHPUnit\Framework\TestCase
        {
            use HidingWrapper { verify as testGo; }
        }
        PHP_WRAP);
        // because the analysis finds a class it does not analyze only once PHP has loaded it
        require_once $sandbox->root.'/tests/Gates/Fixtures/HidingWrapper.php';

        self::assertSame('tests/HidingWrapperTest.php:7 intakt.testMethodSentence Test method Intakt\Sample\HidingWrapperTest::testGo()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAnAliasOfANameAWrapperThatIsNotAnalyzedHidesStaysHidden(): void
    {
        $sandbox = $this->emptySandbox();
        // because the gate excludes its fixtures folder, a wrapper there stands for an unchanged file a changed-files run leaves out
        $sandbox->write('tests/Gates/Fixtures/ConcealingWrapper.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait ConcealingLeaf { public function testGo(): void {} }\ntrait ConcealingWrapper { use ConcealingLeaf { testGo as private; testGo as helper; } }\n");
        $sandbox->write('tests/ConcealingWrapperTest.php', <<<'PHP_WRAP'
        <?php

        namespace Intakt\Sample;

        final class ConcealingWrapperTest extends \PHPUnit\Framework\TestCase
        {
            use ConcealingWrapper { testGo as testIt; }
        }
        PHP_WRAP);
        // because the analysis finds a class it does not analyze only once PHP has loaded it
        require_once $sandbox->root.'/tests/Gates/Fixtures/ConcealingWrapper.php';

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testAShortTestMethodOfATraitUnderSourceUsedWithoutAliasPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-src-trait-without-alias');
    }

    public function testAShortAliasInAClassThatIsNoTestCasePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-alias-outside-test-case');
    }

    protected function ruleFor(string $root): TestMethodSentenceRule
    {
        return new TestMethodSentenceRule($root);
    }

    /**
     * @return array<Collector<Node, mixed>>
     */
    #[Override]
    protected function getCollectors(): array
    {
        $parser = self::getContainer()->getService('defaultAnalysisParser');
        self::assertInstanceOf(Parser::class, $parser);

        return [new TestMethodCollector(), new TraitAliasCollector($parser), new TraitMethodLineCollector(), new TestProviderCollector(self::createReflectionProvider())];
    }
}
