<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoStaticPropertyRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoStaticPropertyRule>
 */
#[CoversClass(NoStaticPropertyRule::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoStaticPropertyRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AFinalReadonlyServiceWithAStaticMethodAConstantAndAStaticVariablePassesStaticAnalysis' => ['static-members-without-static-property'],
            'AnAnonymousClassWithAStaticPropertyPassesStaticAnalysis' => ['anonymous-class-with-static-property'],
            'AServiceWithAStaticPropertyFailsStaticAnalysis' => ['static-property-service', 'src/Mailer.php:9 intakt.noStaticProperty Property Mailer::$sent is static: no static state', 'src/Mailer.php:9 intakt.statefulService Service property $sent is not readonly'],
            'ADoctrineEntityWithAStaticPropertyFailsStaticAnalysis' => ['static-property-entity', 'src/Report.php:12 intakt.noStaticProperty Property Report::$latest is static'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAServiceWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-service', 'src/Mailer.php:9 intakt.noStaticProperty Property Mailer::$sent is static: no static state');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('static-property-service', 'keep the value in an instance property, a service or a local variable.');
    }

    public function testADoctrineEntityWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-entity', 'src/Report.php:12 intakt.noStaticProperty Property Report::$latest is static');
    }

    public function testAFinalReadonlyServiceWithAStaticMethodAConstantAndAStaticVariablePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('static-members-without-static-property');
    }

    public function testAFinalDoctrineEntityWithoutAStaticPropertyPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('final-entity');
    }

    public function testAnAbstractClassWithAStaticPropertyPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('abstract-class-with-static-property');
    }

    public function testAnAnonymousClassWithAStaticPropertyPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('anonymous-class-with-static-property');
    }

    public function testATraitWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-trait', 'src/Counting.php:9 intakt.noStaticProperty Property Counting::$count is static');
    }

    public function testEveryPropertyOfOneStaticDeclarationIsReported(): void
    {
        $findings = $this->ruleFindingsOf('static-properties-in-one-declaration');

        self::assertStringContainsString('src/Tally.php:9 intakt.noStaticProperty Property Tally::$passed is static', $findings);
        self::assertStringContainsString('src/Tally.php:10 intakt.noStaticProperty Property Tally::$failed is static', $findings);
    }

    public function testATestClassWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-test-class', 'tests/MailerTest.php:9 intakt.noStaticProperty Property MailerTest::$checkout is static');
    }

    public function testAClassUnderTheGatesCodeWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-gate-class', 'config/gates/src/Strictness.php:9 intakt.noStaticProperty Property Strictness::$level is static');
    }

    public function testAClassUnderBinWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-script-class', 'bin/Runner.php:9 intakt.noStaticProperty Property Runner::$runs is static');
    }

    public function testAClassDeclaredInAFunctionWithAStaticPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('static-property-class-in-function', 'src/factories.php:11 intakt.noStaticProperty Property Product::$made is static');
    }

    public function testAStaticPropertyOutsideTheRepositoryCodeFoldersPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('static-property-migration');
    }

    protected function ruleFor(string $root): NoStaticPropertyRule
    {
        return new NoStaticPropertyRule($root);
    }
}
