<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ParameterLimitRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ParameterLimitRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ParameterLimitRuleTest extends SandboxTestCase
{
    public function testParametersAtTheLimitPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'parameters-at-limit');
    }

    public function testAMethodWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'method-with-four-parameters', 'intakt.parameterLimit Method line() takes 4 parameters, at most 3 allowed');
    }

    public function testAFunctionWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'function-with-four-parameters', 'intakt.parameterLimit Function joined() takes 4 parameters, at most 3 allowed');
    }

    public function testAClosureWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'closure-with-four-parameters', 'intakt.parameterLimit Closure takes 4 parameters, at most 3 allowed');
    }

    public function testAnArrowFunctionWithFourParametersFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'arrow-function-with-four-parameters', 'intakt.parameterLimit Closure takes 4 parameters, at most 3 allowed');
    }

    public function testAConstructorWithManyParametersPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'constructor-with-many-parameters');
    }

    public function testAMethodWhoseFourParametersAVendorInterfaceFixesPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'vendor-method-with-four-parameters');
    }

    public function testAVendorMethodWithAParameterBeyondItsPrototypeFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'vendor-method-with-an-extra-parameter', 'intakt.parameterLimit Method denormalize() takes 5 parameters, at most 3 allowed');
    }

    public function testAMethodWithFourParametersFromARepositoryInterfaceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'repository-interface-method-with-four-parameters', 'src/Report.php:9 intakt.parameterLimit Method line() takes 4 parameters, at most 3 allowed');
    }

    public function testAMethodNamedLikeAPrivateVendorMethodFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'method-named-like-a-private-vendor-method', 'src/ReportWorkflow.php:16 intakt.parameterLimit Method leave() takes 4 parameters, at most 3 allowed');
    }
}
