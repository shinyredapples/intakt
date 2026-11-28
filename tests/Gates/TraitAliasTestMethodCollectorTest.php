<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\PhpUnitTestMethod;
use Intakt\Gates\PhpStan\TestMethodCollector;
use Intakt\Gates\PhpStan\TestMethodName;
use Intakt\Gates\PhpStan\TestMethodSentenceRule;
use Intakt\Gates\PhpStan\TestProviderCollector;
use Intakt\Gates\PhpStan\TraitAliasTestMethodCollector;
use Intakt\Gates\PhpStan\TraitComposition;
use Intakt\Gates\PhpStan\TraitMethod;
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
#[CoversClass(TraitComposition::class)]
#[CoversClass(TraitMethod::class)]
#[CoversClass(TestProviderCollector::class)]
#[UsesClass(TestMethodCollector::class)]
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

    public function testAShortAliasOfAMethodANestedTraitDeclaresIsReportedAtTheAlias(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('tests/Inner.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Inner\n{\n    public function runs(): void\n    {\n    }\n}\n");
        $sandbox->write('tests/Outer.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Outer\n{\n    use Inner;\n}\n");
        $sandbox->write('tests/NestTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class NestTest extends TestCase\n{\n    use Outer {\n        runs as testRuns;\n    }\n}\n");

        self::assertSame('tests/NestTest.php:8 intakt.testMethodSentence Test method Intakt\Sample\NestTest::testRuns()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAnAliasThroughAWrapperTraitReportsTheAliasAndTheOriginalNameAtTheAliasBesideTheDeclaration(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('tests/Inner.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Inner\n{\n    public function testRuns(): void\n    {\n    }\n}\n");
        $sandbox->write('tests/Wrapper.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Wrapper\n{\n    use Inner;\n}\n");
        $sandbox->write('tests/SampleTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class SampleTest extends TestCase\n{\n    use Wrapper {\n        Wrapper::testRuns as testAgain;\n    }\n}\n");

        // because PHPStan visits the method of the nested trait under its own name too, which a plain use of the wrapper reports at the declaration as well
        self::assertSame(
            'tests/Inner.php:6 intakt.testMethodSentence Test method Intakt\Sample\Inner::testRuns()'.self::FINDING."\n"
            .'tests/SampleTest.php:8 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::testAgain()'.self::FINDING."\n"
            .'tests/SampleTest.php:8 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::testRuns()'.self::FINDING,
            $this->ruleFindingsIn($sandbox),
        );
    }

    public function testHidingTheMethodOfTheExcludedTraitLeavesTheSelectedTestJudged(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('tests/Selected.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Selected\n{\n    public function testRuns(): void\n    {\n    }\n}\n");
        $sandbox->write('tests/Excluded.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Excluded\n{\n    public function testRuns(): void\n    {\n    }\n}\n");
        $sandbox->write('tests/SampleTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class SampleTest extends TestCase\n{\n    use Selected, Excluded {\n        Selected::testRuns insteadof Excluded;\n        Selected::testRuns as testAgain;\n        Excluded::testRuns as private;\n    }\n}\n");

        self::assertSame(
            'tests/SampleTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::testAgain()'.self::FINDING."\n"
            .'tests/SampleTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::testRuns()'.self::FINDING,
            $this->ruleFindingsIn($sandbox),
        );
    }

    public function testAProtectedTraitTestMadePublicBesideARenamingAliasIsReportedAtTheAlias(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('tests/Checks.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Checks\n{\n    protected function testRuns(): void\n    {\n    }\n}\n");
        $sandbox->write('tests/SampleTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class SampleTest extends TestCase\n{\n    use Checks {\n        testRuns as public;\n        testRuns as testAgain;\n    }\n}\n");

        self::assertSame('tests/SampleTest.php:9 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::testRuns()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testRenamingAnAliasAWrapperTraitDeclaresReportsBothNamesTheClassRuns(): void
    {
        $sandbox = $this->wrapped('public function runs(): void {}', 'use Inner { runs as testRuns; }', 'Wrapper { testRuns as testAgain; }');

        self::assertSame($this->findingsAtTheClassAlias('testAgain', 'testRuns'), $this->ruleFindingsIn($sandbox));
    }

    public function testATestAWrapperTraitMakesPublicIsReportedUnderBothNamesTheClassRuns(): void
    {
        $sandbox = $this->wrapped('protected function testRuns(): void {}', 'use Inner { testRuns as public; }', 'Wrapper { testRuns as testAgain; }');

        self::assertSame($this->findingsAtTheClassAlias('testAgain', 'testRuns'), $this->ruleFindingsIn($sandbox));
    }

    public function testATestAWrapperTraitMakesProtectedIsNotReportedUnderAnyName(): void
    {
        $sandbox = $this->wrapped('public function testRuns(): void {}', 'use Inner { testRuns as protected; }', 'Wrapper { testRuns as testAgain; }');

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testHidingTheExcludedWrapperOfOneTestLeavesTheSelectedWrapperJudged(): void
    {
        $sandbox = $this->wrapped('public function testRuns(): void {}', 'use Inner;', 'Wrapper, Other { Wrapper::testRuns insteadof Other; Wrapper::testRuns as testAgain; Other::testRuns as private; }');

        self::assertSame($this->findingsAtTheClassAlias('testAgain', 'testRuns'), $this->ruleFindingsIn($sandbox));
    }

    public function testAProviderNamedOnlyByAHiddenTraitTestLeavesThePublicStaticAliasJudged(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('src/Inner.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\Attributes\\DataProvider;\ntrait Inner\n{\n    #[DataProvider('testRows')]\n    public function testRuns(): void {}\n    public static function rows(): iterable { return []; }\n}\n");
        $sandbox->write('src/Wrapper.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Wrapper\n{\n    use Inner;\n}\n");
        $sandbox->write('tests/SampleTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class SampleTest extends TestCase\n{\n    use Wrapper {\n        testRuns as private;\n        testRuns as private testAgain;\n        rows as testRows;\n    }\n}\n");

        self::assertSame('tests/SampleTest.php:10 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::testRows()'.self::FINDING, $this->ruleFindingsIn($sandbox));
    }

    public function testAProviderNamedByARunningTraitTestExemptsThePublicStaticAlias(): void
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('src/Inner.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\Attributes\\DataProvider;\ntrait Inner\n{\n    #[DataProvider('testRows')]\n    public function testRuns(): void {}\n    public static function rows(): iterable { return []; }\n}\n");
        $sandbox->write('src/Wrapper.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Wrapper\n{\n    use Inner;\n}\n");
        $sandbox->write('tests/SampleTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class SampleTest extends TestCase\n{\n    use Wrapper {\n        rows as testRows;\n    }\n}\n");

        self::assertSame('', $this->ruleFindingsIn($sandbox));
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

    private function wrapped(string $inner, string $wrapper, string $use): Sandbox
    {
        $sandbox = Sandbox::empty();
        $sandbox->write('src/Inner.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Inner\n{\n    ".$inner."\n}\n");
        $sandbox->write('src/Wrapper.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Wrapper\n{\n    ".$wrapper."\n}\n");
        $sandbox->write('src/Other.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\ntrait Other\n{\n    use Inner;\n}\n");
        $sandbox->write('tests/SampleTest.php', "<?php\ndeclare(strict_types=1);\nnamespace Intakt\\Sample;\nuse PHPUnit\\Framework\\TestCase;\nfinal class SampleTest extends TestCase\n{\n    use ".$use."\n}\n");

        return $sandbox;
    }

    private function findingsAtTheClassAlias(string ...$methods): string
    {
        return implode("\n", array_map(static fn (string $method): string => 'tests/SampleTest.php:7 intakt.testMethodSentence Test method Intakt\Sample\SampleTest::'.$method.'()'.self::FINDING, $methods));
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
        return [new TestMethodCollector(), new TraitAliasTestMethodCollector(self::createReflectionProvider()), new TestProviderCollector(self::createReflectionProvider())];
    }
}
