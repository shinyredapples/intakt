<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ParameterLimitRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ParameterLimitRule>
 */
#[CoversClass(ParameterLimitRule::class)]
#[UsesClass(RepositoryCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ParameterLimitRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'ParametersAtTheLimitPassStaticAnalysis' => ['parameters-at-limit'],
            'AMethodWithFourParametersFailsStaticAnalysis' => ['method-with-four-parameters', 'src/Report.php:9 intakt.parameterLimit Method line() takes 4 parameters, at most 3 allowed'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAFunctionWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('function-with-four-parameters', 'intakt.parameterLimit Function joined() takes 4 parameters, at most 3 allowed');
    }

    public function testAClosureWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('closure-with-four-parameters', 'intakt.parameterLimit Closure takes 4 parameters, at most 3 allowed');
    }

    public function testAnArrowFunctionWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('arrow-function-with-four-parameters', 'intakt.parameterLimit Closure takes 4 parameters, at most 3 allowed');
    }

    public function testAConstructorWithManyParametersPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('constructor-with-many-parameters');
    }

    public function testAMethodWhoseFourParametersAVendorInterfaceFixesPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('vendor-method-with-four-parameters');
    }

    public function testAVendorMethodWithAParameterBeyondItsPrototypeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('vendor-method-with-an-extra-parameter', 'intakt.parameterLimit Method denormalize() takes 5 parameters, at most 3 allowed');
    }

    public function testAMethodWithFourParametersFromARepositoryInterfaceFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('repository-interface-method-with-four-parameters', 'src/Report.php:9 intakt.parameterLimit Method line() takes 4 parameters, at most 3 allowed');
    }

    public function testAMethodNamedLikeAPrivateVendorMethodFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('method-named-like-a-private-vendor-method', 'src/ReportWorkflow.php:16 intakt.parameterLimit Method leave() takes 4 parameters, at most 3 allowed');
    }

    protected function ruleFor(string $root): ParameterLimitRule
    {
        return new ParameterLimitRule($root);
    }
}
