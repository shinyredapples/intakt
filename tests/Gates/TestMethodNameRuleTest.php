<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodNameRule;
use Intakt\Gates\StaticAnalysisGate;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodNameRule>
 */
#[CoversClass(TestMethodNameRule::class)]
#[CoversClass(TestMethodCollector::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class TestMethodNameRuleTest extends PhpStanRuleTestCase
{
    public function testATestMethodWithFewerThanThreeWordsFailsStaticAnalysisWithHowToComply(): void
    {
        $this->assertRuleRejects('short-test-method', 'tests/MailerTest.php:11 intakt.testName Test method testItSends() does not read as a sentence: name it test followed by at least three words in camel case');
    }

    public function testATestMethodNamedAsASentenceOfThreeOrMoreWordsPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('sentence-test-methods');
    }

    public function testAnAcronymCountsAsOneWordSoTwoWordsFail(): void
    {
        $this->assertRuleRejects('acronym-test-method', 'tests/MailerTest.php:11 intakt.testName Test method testMCPWorks()');
    }

    public function testAnUnderscoreBetweenTheWordsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('underscore-test-method', 'tests/MailerTest.php:11 intakt.testName Test method testIt_Sends_Now()');
    }

    public function testADigitCountsAsPartOfItsWordSoTwoWordsFail(): void
    {
        $this->assertRuleRejects('digit-word-test-method', 'tests/MailerTest.php:11 intakt.testName Test method testItSends2()');
    }

    public function testADataProviderAndAHelperWithoutThePrefixAreNotJudged(): void
    {
        $this->assertRuleAccepts('data-provider-and-helper');
    }

    public function testADataProviderNamedByAClassConstantIsNotJudged(): void
    {
        $this->assertRuleAccepts('constant-data-provider');
    }

    public function testAProviderLikeMethodThatNoDataProviderNamesIsJudged(): void
    {
        $this->assertRuleRejects('unnamed-provider-test-method', 'tests/MailerTest.php:11 intakt.testName Test method testCases()');
    }

    public function testAProviderImplementedInASubclassOfTheTestCaseThatNamesItIsNotJudged(): void
    {
        $this->assertRuleAccepts('subclass-data-provider');
    }

    public function testAProviderLikeMethodOfASubclassThatNoParentNamesIsJudged(): void
    {
        $this->assertRuleRejects('subclass-unnamed-provider', 'tests/MailerTest.php:25 intakt.testName Test method testCases()');
    }

    public function testATestPrefixedMethodOfAnInterfaceAndItsTestDoubleIsNotJudged(): void
    {
        $this->assertRuleAccepts('interface-and-double-test-method');
    }

    public function testAShortTestMethodOfAnAbstractTestCaseIsJudged(): void
    {
        $this->assertRuleRejects('abstract-test-case-test-method', 'tests/MailerTestCase.php:11 intakt.testName Test method testShort()');
    }

    public function testAMethodMarkedAsTestWithoutThePrefixIsJudged(): void
    {
        $this->assertRuleRejects('test-attribute-method', 'tests/MailerTest.php:13 intakt.testName Test method works()');
    }

    public function testAMethodMarkedAsTestWithoutThePrefixFailsEvenWithThreeWords(): void
    {
        $this->assertRuleRejects('sentence-test-attribute-method', 'tests/MailerTest.php:13 intakt.testName Test method aStaleLockIsReleased()');
    }

    public function testAMethodMarkedAsTestWithThePrefixAndThreeWordsPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('prefixed-test-attribute-method');
    }

    public function testALowercaseLetterAfterThePrefixFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('lowercase-after-prefix-test-method', 'tests/MailerTest.php:11 intakt.testName Test method testitSendsNow()');
    }

    public function testAnAttributeOfAnotherNamespaceNamedDataProviderExemptsNothing(): void
    {
        self::assertStringStartsWith('tests/MailerTest.php:25 intakt.testName Test method testShort()', $this->ruleFindingsOf('lookalike-data-provider'));
    }

    public function testAProviderOfAnotherClassNamedByDataProviderExternalIsNotJudgedAndItsNeighborIs(): void
    {
        $findings = $this->ruleFindingsOf('external-data-provider');

        self::assertStringStartsWith('tests/MailerTest.php:17 intakt.testName Test method testOthers()', $findings);
        self::assertStringNotContainsString('testCases', $findings);
    }

    public function testAProviderNamedByNamedArgumentsThroughAnImportAliasIsNotJudged(): void
    {
        $this->assertRuleAccepts('named-argument-data-provider');
    }

    public function testAProviderDeclaredByAParentClassOrAnImportedTraitIsNotJudged(): void
    {
        $this->assertRuleAccepts('inherited-and-trait-data-provider');
    }

    public function testATestMethodImportedFromATraitIsJudgedOnceAtItsDeclaration(): void
    {
        $findings = $this->ruleFindingsOf('trait-test-method');

        self::assertStringStartsWith('tests/MailerTest.php:11 intakt.testName Test method testShort()', $findings);
        self::assertSame(1, substr_count($findings, 'intakt.testName'));
    }

    public function testAMethodNamedTestOutsideTheTestsFolderIsNotJudged(): void
    {
        $this->assertRuleAccepts('short-test-method-outside-tests');
    }

    protected function ruleFor(string $root): TestMethodNameRule
    {
        return new TestMethodNameRule($root);
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
