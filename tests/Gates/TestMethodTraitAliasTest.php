<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\PhpUnitTestMethod;
use Intakt\Gates\PhpStan\SentenceTestName;
use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Intakt\Gates\PhpStan\TestProviderCollector;
use Intakt\Gates\PhpStan\TraitAliasCollector;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodSentenceRule>
 */
#[CoversClass(TestMethodSentenceRule::class)]
#[CoversClass(TraitAliasCollector::class)]
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
        return [new TestMethodCollector(), new TraitAliasCollector(), new TestProviderCollector(self::createReflectionProvider())];
    }
}
