<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\PhpUnitTestMethod;
use Intakt\Gates\PhpStan\SentenceTestName;
use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Intakt\Gates\PhpStan\TestProviderCollector;
use Intakt\Gates\PhpStan\TraitAliasCollector;
use Intakt\Gates\PhpStan\TraitMethodDeclaration;
use Intakt\Gates\PhpStan\TraitMethodLineCollector;
use Intakt\Gates\PhpStan\TraitUseScope;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodSentenceRule>
 */
#[CoversClass(TestMethodSentenceRule::class)]
#[CoversClass(TraitAliasCollector::class)]
#[CoversClass(TraitMethodLineCollector::class)]
#[CoversClass(TraitMethodDeclaration::class)]
#[CoversClass(TraitUseScope::class)]
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
        $sandbox->write('tests/MarkedTest.php', <<<'PHP'
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
            PHP);

        self::assertSame(
            'tests/MarkedTest.php:18 intakt.testMethodSentence Test method Intakt\Sample\MarkedTest::checkAgain()'.self::FINDING."\n"
            .'tests/MarkedTest.php:11 intakt.testMethodSentence Test method Intakt\Sample\MarkedChecks::check()'.self::FINDING,
            $this->ruleFindingsIn($sandbox),
        );
    }

    public function testOnlyTheAliasOfTheWrapperTraitThatInsteadofSelectsIsReported(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/PrecedenceTest.php', <<<'PHP'
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
            PHP);

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
        $sandbox->write('tests/SameLineTest.php', <<<'PHP'
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
            PHP);

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
        $sandbox->write('tests/TwiceTest.php', <<<'PHP'
            <?php

            namespace Intakt\Sample;

            final class TwiceTest extends \PHPUnit\Framework\TestCase
            {
                use TwiceChecks {
                    testRuns as testRunsTheFirstWay;
                    testRuns as testRunsTheSecondWay;
                }
            }
            PHP);

        self::assertSame('tests/TwiceTest.php:8 intakt.testMethodSentence Test method Intakt\Sample\TwiceTest::testRuns()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAnOriginalMadePrivateBesideAnAliasWithoutModifierIsHiddenWhileTheAliasStaysPublic(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/HiddenOriginalChecks.php', "<?php\n\nnamespace Intakt\\Sample;\n\ntrait HiddenOriginalChecks { public function testRuns(): void {} }\n");
        $sandbox->write('tests/HiddenOriginalTest.php', <<<'PHP'
            <?php

            namespace Intakt\Sample;

            final class HiddenOriginalTest extends \PHPUnit\Framework\TestCase
            {
                use HiddenOriginalChecks {
                    testRuns as private;
                    testRuns as testRunsAgain;
                }
            }
            PHP);

        self::assertSame('tests/HiddenOriginalTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\HiddenOriginalTest::testRunsAgain()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAShortAliasOfAWrapperTraitThatTheTestClassMakesPrivatePassesStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/PrivateWrapper.php', <<<'PHP'
            <?php

            namespace Intakt\Sample;

            trait PrivateWrapperChecks { public function helper(): void {} }
            trait PrivateWrapper { use PrivateWrapperChecks { helper as testGo; } }

            final class PrivateWrapperTest extends \PHPUnit\Framework\TestCase
            {
                use PrivateWrapper { testGo as private; }
            }
            PHP);

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
        return [new TestMethodCollector(), new TraitAliasCollector(), new TraitMethodLineCollector(), new TestProviderCollector(self::createReflectionProvider())];
    }
}
