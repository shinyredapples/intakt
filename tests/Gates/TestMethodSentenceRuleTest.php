<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Intakt\Gates\StaticAnalysisGate;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodSentenceRule>
 */
#[CoversClass(TestMethodSentenceRule::class)]
#[CoversClass(TestMethodCollector::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class TestMethodSentenceRuleTest extends PhpStanRuleTestCase
{
    private string|false $changedFilesOnly = false;

    public function testATestMethodOfTwoWordsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('test-method-two-words', 'tests/SendingTest.php:11 intakt.testMethodSentence Test method Intakt\Sample\SendingTest::testFormSent() is not named as a sentence');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('test-method-two-words', 'name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().');
    }

    public function testATestMethodOfThreeWordsPassesBesideATwoWordOne(): void
    {
        self::assertCount(1, explode("\n", $this->ruleFindingsOf('test-method-two-words')));
    }

    public function testSentencesWithAcronymsDigitsAndStaticTestsPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-sentences');
    }

    public function testTestMethodsWithUnderscoresOrWithoutCapitalsFailStaticAnalysis(): void
    {
        $findings = explode("\n", $this->ruleFindingsOf('test-method-not-camel-case'));

        self::assertCount(2, $findings);
        self::assertStringStartsWith('tests/MailTest.php:11 intakt.testMethodSentence Test method Intakt\Sample\MailTest::test_it_sends_mail()', $findings[0]);
        self::assertStringStartsWith('tests/MailTest.php:16 intakt.testMethodSentence Test method Intakt\Sample\MailTest::testsendsthemailtoeveryone()', $findings[1]);
    }

    public function testShortDataProvidersNamedInTheClassOrFromOutsidePassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-providers');
    }

    public function testAnInterfaceAndADoubleDeclaringTestConnectionPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-interface-and-double');
    }

    public function testAProviderASubclassImplementsForItsAbstractBasePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-subclass-provider');
    }

    public function testAProviderAClassImplementsForItsTraitPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-trait-provider');
    }

    public function testAShortTestMethodOfATraitIsReportedOnceInTheTraitWhenTestCasesUseIt(): void
    {
        $findings = $this->ruleFindingsOf('test-method-in-trait');

        self::assertSame('tests/SharedChecks.php:9 intakt.testMethodSentence Test method Intakt\Sample\SharedChecks::testRuns() is not named as a sentence: name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().', $findings);
    }

    public function testAShortTestMethodOfATraitNoTestCaseUsesPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-trait-outside-test-case');
    }

    public function testAShortTestMethodOfAnAbstractTestCaseFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('test-method-abstract-test-case', 'tests/CandidateTestCase.php:11 intakt.testMethodSentence Test method Intakt\Sample\CandidateTestCase::testVisible()');
    }

    public function testAShortTestMethodOutsideTheTestsFolderPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-outside-tests');
    }

    public function testAProviderAParentDeclaresAndASubclassTestNamesPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-inherited-provider');
    }

    public function testAChangedFilesRunWithoutTheTestNamingAStaticProviderReportsNoFinding(): void
    {
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'=1');

        self::assertSame('', $this->ruleFindingsIn($this->providerWithoutItsTest()));
    }

    public function testARunWithoutTheSignalReportsAStaticProviderNoAnalyzedTestNames(): void
    {
        self::assertStringStartsWith('tests/SharedRows.php:14 intakt.testMethodSentence Test method Intakt\Sample\SharedRows::testRows()', $this->ruleFindingsIn($this->providerWithoutItsTest()));
    }

    public function testAChangedFilesRunStillReportsAShortTestMethodThatIsNotStatic(): void
    {
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'=1');

        $this->assertRuleRejects('test-method-two-words', 'Intakt\Sample\SendingTest::testFormSent()');
    }

    public function testAMethodMarkedAsTestWithoutTheTestPrefixFailsStaticAnalysis(): void
    {
        self::assertSame('tests/AttributeTest.php:13 intakt.testMethodSentence Test method Intakt\Sample\AttributeTest::works() is not named as a sentence: name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().', $this->ruleFindingsOf('test-method-test-attribute'));
    }

    public function testAnArticleBeforeAnAcronymCountsAsAWordOfItsOwn(): void
    {
        self::assertSame('tests/ArticleTest.php:21 intakt.testMethodSentence Test method Intakt\Sample\ArticleTest::testURLWorks() is not named as a sentence: name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().', $this->ruleFindingsOf('test-method-article-before-acronym'));
    }

    public function testAShortTestMethodOfAnAnonymousTestCasePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-anonymous-class');
    }

    #[Override]
    protected function setUp(): void
    {
        $this->changedFilesOnly = getenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
        putenv(StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE);
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        putenv(false === $this->changedFilesOnly ? StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE : StaticAnalysisGate::CHANGED_FILES_ONLY_VARIABLE.'='.$this->changedFilesOnly);
        parent::tearDown();
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
        return [new TestMethodCollector(self::createReflectionProvider())];
    }

    // because a changed-files run where only the provider changed does not analyze the test that names it
    private function providerWithoutItsTest(): Sandbox
    {
        $sandbox = $this->sandboxWith('test-method-providers');
        unlink($sandbox->root.'/tests/CasesTest.php');

        return $sandbox;
    }
}
