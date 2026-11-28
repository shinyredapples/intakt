<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\PhpUnitTestMethod;
use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodName;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Intakt\Gates\PhpStan\TestProviderCollector;
use Intakt\Gates\PhpStan\TraitAliasTestMethodCollector;
use Intakt\Gates\StaticAnalysisGate;
use Override;
use PhpParser\Node;
use PHPStan\Collectors\Collector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<TestMethodSentenceRule>
 */
#[CoversClass(TraitAliasTestMethodCollector::class)]
#[CoversClass(TestMethodName::class)]
#[CoversClass(TestMethodSentenceRule::class)]
#[UsesClass(TestMethodCollector::class)]
#[UsesClass(TestProviderCollector::class)]
#[UsesClass(PhpUnitTestMethod::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class TraitAliasTestMethodCollectorTest extends PhpStanRuleTestCase
{
    private const string FINDING = ' is not named as a sentence: name it test followed by at least three camel-case words that state the behavior, such as testTheFormIsSent().';

    private string|false $changedFilesOnly = false;

    public function testAShortAliasOfATraitMethodUnderSrcIsReportedAtTheAliasInTheTestClass(): void
    {
        self::assertSame(
            'tests/SendingTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\SendingTest::testRuns()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-trait-in-src'),
        );
    }

    public function testAShortAliasOfATraitUnderTestsIsReportedAtTheAliasBesideASentenceAlias(): void
    {
        self::assertSame(
            'tests/MailTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\MailTest::testSends()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-trait-in-tests'),
        );
    }

    public function testTheOriginalNameAClassStillRunsIsReportedAtTheAliasBesideTheShortAlias(): void
    {
        self::assertSame(
            'tests/SharedChecks.php:9 intakt.testMethodSentence Test method Intakt\Sample\SharedChecks::testRuns()'.self::FINDING."\n"
            .'tests/FirstTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\FirstTest::testRunsAgain()'.self::FINDING."\n"
            .'tests/FirstTest.php:12 intakt.testMethodSentence Test method Intakt\Sample\FirstTest::testRuns()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-of-trait-test'),
        );
    }

    public function testAMethodTheClassDeclaresUnderTheAliasNameIsReportedOnlyWhereItIsDeclared(): void
    {
        self::assertSame(
            'tests/OwnTest.php:15 intakt.testMethodSentence Test method Intakt\Sample\OwnTest::testRuns()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-overridden'),
        );
    }

    public function testAShortAliasATraitDeclaresIsReportedAtTheAliasInThatTrait(): void
    {
        self::assertSame(
            'tests/Steps.php:10 intakt.testMethodSentence Test method Intakt\Sample\Steps::testRuns()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-in-trait'),
        );
    }

    public function testPrivateAndProtectedAliasesThatPhpUnitDoesNotRunPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('test-method-alias-hidden');
    }

    public function testAnAliasOfATestExcludedByInsteadofIsReportedOnlyUnderTheAlias(): void
    {
        self::assertSame(
            'tests/BothTest.php:13 intakt.testMethodSentence Test method Intakt\Sample\BothTest::testSecond()'.self::FINDING,
            $this->ruleFindingsOf('test-method-alias-insteadof'),
        );
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
        return [new TestMethodCollector(), new TraitAliasTestMethodCollector(), new TestProviderCollector(self::createReflectionProvider())];
    }
}
