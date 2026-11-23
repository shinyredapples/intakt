<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodSentenceRule>
 */
#[CoversClass(TestMethodSentenceRule::class)]
#[CoversClass(TestMethodCollector::class)]
final class TestMethodSentenceRuleTest extends PhpStanRuleTestCase
{
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
        return [new TestMethodCollector()];
    }
}
