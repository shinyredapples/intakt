<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\FinalClassRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<FinalClassRule>
 */
#[CoversClass(FinalClassRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class FinalClassRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): FinalClassRule
    {
        return new FinalClassRule($root);
    }

    public function testANonFinalReadonlyServiceFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-service', 'src/Mailer.php:7 intakt.finalClass Class Mailer is not final: declare it final');
    }

    public function testAFinalReadonlyServicePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('final-readonly-service');
    }

    public function testANonFinalDoctrineEntityFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-entity', 'src/Report.php:10 intakt.finalClass Class Report is not final');
    }

    public function testAFinalDoctrineEntityPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('final-entity');
    }

    public function testAnAbstractClassPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('abstract-class');
    }

    public function testAnAnonymousClassPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('anonymous-class');
    }

    public function testAnInterfaceAnEnumAndATraitPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('interface-enum-and-trait');
    }

    public function testANonFinalClassUnderTestsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-test-class', 'tests/MailerTest.php:7 intakt.finalClass Class MailerTest is not final');
    }

    public function testANonFinalClassUnderTheGatesCodeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-gate-class', 'config/gates/src/Strictness.php:7 intakt.finalClass Class Strictness is not final');
    }

    public function testANonFinalClassUnderBinFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-script-class', 'bin/Runner.php:7 intakt.finalClass Class Runner is not final');
    }

    public function testANonFinalClassDeclaredInAMethodOfAnUnusedTraitFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-class-in-unused-trait', 'src/Factories.php:11 intakt.finalClass Class Product is not final');
    }

    public function testANonFinalClassDeclaredInsideAConditionFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-conditional-class', 'src/Polyfill.php:8 intakt.finalClass Class Polyfill is not final');
    }

    public function testANonFinalClassOutsideTheRepositoryCodeFoldersPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('non-final-migration');
    }
}
