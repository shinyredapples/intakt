<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpStan\NoMockOptOutRule;
use Intakt\Gates\PhpStan\NoNestedTryRule;
use Intakt\Gates\PhpStan\ParameterLimitRule;
use Intakt\Gates\RectorGate;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CodingStandardsGate::class)]
#[CoversClass(StaticAnalysisGate::class)]
#[CoversClass(RectorGate::class)]
#[CoversClass(ParameterLimitRule::class)]
#[CoversClass(NoNestedTryRule::class)]
#[CoversClass(NoMockOptOutRule::class)]
final class CodeGatesTest extends SandboxTestCase
{
    public function testACompliantClassPassesEveryCodeGate(): void
    {
        foreach (['cs', 'phpstan', 'rector', 'comments', 'architecture'] as $gate) {
            $this->assertAcceptedBy($gate, 'clean');
        }
    }

    public function testAFullyQualifiedClassNameInCodeFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'fully-qualified-name-in-code', 'src/Report.php: global_namespace_import');
    }

    public function testAFullyQualifiedClassNameInAnAttributeFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'fully-qualified-name-in-attribute', 'src/Marker.php: global_namespace_import');
    }

    public function testAFullyQualifiedClassNameInADocblockFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'fully-qualified-name-in-docblock', 'src/Report.php: fully_qualified_strict_types');
    }

    public function testASuperfluousDocblockFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'superfluous-docblock', 'no_superfluous_phpdoc_tags');
    }

    public function testAClassNameWrittenAsAStringFailsTheRectorDryRun(): void
    {
        $this->assertRejectedBy('rector', 'class-name-as-string', 'StringClassNameToClassConstantRector');
    }

    public function testDeadCodeFailsTheRectorDryRun(): void
    {
        $this->assertRejectedBy('rector', 'dead-code', 'RemoveUnusedVariableAssignRector');
    }

    public function testANonBooleanConditionFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'loose-condition', 'if.condNotBoolean');
    }

    public function testADeprecatedCallFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'deprecated-call', 'function.deprecated');
    }

    public function testAMethodAboveTheCognitiveComplexityLimitFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'too-complex-method', 'complexity.functionLike');
    }

    public function testAClassAboveTheCognitiveComplexityLimitFailsStaticAnalysisEvenWhenEveryMethodIsSimple(): void
    {
        $this->assertRejectedBy('phpstan', 'class-too-complex', 'complexity.classLike');
    }

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

    public function testATryInsideACatchFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'nested-try', 'intakt.nestedTry');
    }

    public function testATryInsideATryFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'try-in-try', 'intakt.nestedTry');
    }

    public function testATryInsideAFinallyFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'try-in-finally', 'intakt.nestedTry');
    }

    public function testATryInAClosureInsideATryPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'try-in-closure');
    }

    public function testOptingOutOfMockExpectationsFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'mock-opt-out', 'intakt.mockOptOut');
    }

    public function testTriesInSeparateMethodsPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'sibling-tries');
    }

    public function testAConstructorWithManyParametersPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'constructor-with-many-parameters');
    }

    public function testStaticAnalysisRunsAtMaxLevelWithoutABaseline(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon');

        self::assertStringContainsString('level: max', $configuration);
        self::assertStringNotContainsStringIgnoringCase('baseline', $configuration);
        self::assertStringNotContainsString('ignoreErrors', $configuration);
    }
}
