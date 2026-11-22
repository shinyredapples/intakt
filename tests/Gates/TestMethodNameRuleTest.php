<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\TestMethodNameRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodNameRule>
 */
#[CoversClass(TestMethodNameRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class TestMethodNameRuleTest extends PhpStanRuleTestCase
{
    public function testATestMethodWithFewerThanThreeWordsFailsStaticAnalysisWithHowToComply(): void
    {
        $this->assertRuleRejects('short-test-method', 'tests/MailerTest.php:9 intakt.testName Test method testItSends() does not read as a sentence: name it test followed by at least three words in camel case');
    }

    public function testATestMethodNamedAsASentenceOfThreeOrMoreWordsPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('sentence-test-methods');
    }

    public function testAnAcronymCountsAsOneWordSoTwoWordsFail(): void
    {
        $this->assertRuleRejects('acronym-test-method', 'tests/MailerTest.php:9 intakt.testName Test method testMCPWorks()');
    }

    public function testADataProviderAndAHelperWithoutThePrefixAreNotJudged(): void
    {
        $this->assertRuleAccepts('data-provider-and-helper');
    }

    public function testAMethodNamedTestOutsideTheTestsFolderIsNotJudged(): void
    {
        $this->assertRuleAccepts('short-test-method-outside-tests');
    }

    protected function ruleFor(string $root): TestMethodNameRule
    {
        return new TestMethodNameRule($root);
    }
}
