<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\FinalClassRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Process\Process;

/**
 * @extends PhpStanRuleTestCase<FinalClassRule>
 */
#[CoversClass(FinalClassRule::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class FinalClassRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): FinalClassRule
    {
        return new FinalClassRule($root);
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AFinalDoctrineEntityPassesStaticAnalysis' => ['final-entity'],
            'AnAnonymousClassInAClosurePassesStaticAnalysis' => ['anonymous-class-in-closure'],
            'ANonFinalReadonlyServiceFailsStaticAnalysis' => ['non-final-service', 'src/Mailer.php:7 intakt.finalClass Class Mailer is not final: declare it final'],
        ], 'src/');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
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

    public function testANonFinalClassDeclaredInAFunctionFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('non-final-class-in-function', 'src/factories.php:9 intakt.finalClass Class Product is not final');
    }

    public function testANonFinalClassInAnExtensionlessBinScriptFailsTheGate(): void
    {
        $sandbox = Sandbox::withFixture('non-final-extensionless-script-class');
        $sandbox->borrowKernel();

        try {
            $run = $sandbox->check('--only=phpstan');
        } finally {
            $sandbox->remove();
        }

        self::assertStringContainsString('bin/probe:8 intakt.finalClass Class Runner is not final', $run->findingsOf('phpstan'), $run->output());
    }

    public function testABracedNamespaceFileReportsEachNonFinalClassAndSkipsTheFinalOne(): void
    {
        $findings = $this->ruleFindingsOf('non-final-classes-in-braced-namespaces');

        self::assertStringContainsString('src/Pair.php:6 intakt.finalClass Class Open is not final', $findings);
        self::assertStringContainsString('src/Pair.php:16 intakt.finalClass Class AlsoOpen is not final', $findings);
        self::assertStringNotContainsString('Closed', $findings);
    }

    public function testTheNestedClassFixturesAreValidPhp(): void
    {
        $fixtures = [__DIR__.'/Fixtures/non-final-class-in-function/src/factories.php.fixture', __DIR__.'/Fixtures/non-final-conditional-class/src/Polyfill.php.fixture'];
        $invalid = array_values(array_filter($fixtures, static fn (string $fixture): bool => 0 !== new Process([PHP_BINARY, '-l', $fixture])->run()));

        self::assertSame([], $invalid);
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
