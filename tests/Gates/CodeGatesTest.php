<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpStan\ImportFormRule;
use Intakt\Gates\PhpStan\NoMockOptOutRule;
use Intakt\Gates\PhpStan\NoNestedTryRule;
use Intakt\Gates\PhpStan\ParameterLimitRule;
use Intakt\Gates\PhpStan\StatefulServiceResetRule;
use Intakt\Gates\RectorGate;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CodingStandardsGate::class)]
#[CoversClass(StaticAnalysisGate::class)]
#[CoversClass(RectorGate::class)]
#[CoversClass(ParameterLimitRule::class)]
#[CoversClass(NoNestedTryRule::class)]
#[CoversClass(NoMockOptOutRule::class)]
#[CoversClass(ImportFormRule::class)]
#[CoversClass(StatefulServiceResetRule::class)]
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

    public function testAFullyQualifiedFunctionOrConstantFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'qualified-function-and-constant', 'src/Report.php: native_constant_invocation, native_function_invocation');
    }

    public function testAPlainFunctionAndConstantPassTheCodingStandardsAndStaticAnalysis(): void
    {
        $this->assertAcceptedBy('cs', 'plain-function-and-constant');
        $this->assertAcceptedBy('phpstan', 'plain-function-and-constant');
    }

    public function testAFunctionOrConstantImportFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('imported-function-and-constant')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Report.php:7 intakt.functionOrConstantImport', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/Report.php:9 intakt.functionOrConstantImport', $run->findingsOf('phpstan'));
    }

    public function testAFunctionOrConstantImportIsRemovedByTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'imported-function-and-constant', 'src/Report.php: global_namespace_import');
    }

    public function testAnImportAliasWithoutAShortNameClashFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'unneeded-import-alias', 'src/Report.php:7 intakt.unneededImportAlias');
    }

    public function testAnImportAliasThatResolvesAShortNameClashPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'import-alias-for-short-name-clash');
    }

    public function testAnImportAliasThatResolvesAShortNameClashInAnotherLetterCasePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'import-alias-for-short-name-clash-in-another-case');
    }

    public function testAnImportAliasWithoutAShortNameClashInItsOwnNamespaceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'import-alias-in-another-namespace', 'src/Report.php:17 intakt.unneededImportAlias');
    }

    public function testAnImportAliasForTheShortNameOfTheDeclaredClassPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'import-alias-for-declared-class');
    }

    public function testTheDoctrineOrmAliasPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'doctrine-orm-alias');
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

    public function testAServiceWithAMutablePropertyFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('mutable-service')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:9 intakt.statefulService Service property $sent', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/Mailer.php:11 intakt.statefulService Service property $sender', $run->findingsOf('phpstan'));
    }

    public function testAServiceWithAMutablePropertyAndResetInterfacePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'resettable-service');
    }

    public function testAReadonlyServicePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'readonly-service');
    }

    public function testEntitiesExceptionsTheKernelAndClassesExcludedFromTheContainerWithMutablePropertiesPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'mutable-data');
    }

    public function testDoctrineColumnTypesWithMutablePropertiesPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'framework-built');
    }

    public function testAnOptionPropertyAloneDoesNotExemptAServiceFromStaticAnalysis(): void
    {
        $run = $this->sandboxWith('option-property-service')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/SendInput.php:11 intakt.statefulService Service property $uses', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/SendInput.php:13 intakt.statefulService Service property $subject', $run->findingsOf('phpstan'));
    }

    public function testOnlyPropertiesDeclaredUnderSrcAreReportedOnAServiceExtendingAFrameworkBaseClass(): void
    {
        $run = $this->sandboxWith('framework-base')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Count.php:15 intakt.statefulService Service property $runs', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('Command::$', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('src/Greet.php', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('src/Home.php', $run->findingsOf('phpstan'));
    }

    public function testOnlyABackedHookedPropertyOfAServiceFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('hooked-properties')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:13 intakt.statefulService Service property $subject', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('$greeting', $run->findingsOf('phpstan'));
    }

    public function testADomainServiceWithAMutablePropertyFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'domain-service', 'src/Core/Review/Domain/VerdictPolicy.php:10 intakt.statefulService Service property $cache');
    }

    public function testAStaticMutablePropertyOfAServiceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'static-service', 'src/Mailer.php:9 intakt.statefulService Service property $sent');
    }

    public function testMutablePropertiesFromATraitAndAParentFailStaticAnalysisOnTheService(): void
    {
        $run = $this->sandboxWith('inherited-state')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:7 intakt.statefulService Service property Counting::$count', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/Mailer.php:7 intakt.statefulService Service property Sender::$last', $run->findingsOf('phpstan'));
    }

    public function testOnlyClassesUnderSrcCountAsServicesInStaticAnalysis(): void
    {
        $run = $this->sandboxWith('unregistered-state')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Mailer.php:9 intakt.statefulService Service property $sent', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('tests/Support/RecordingMailer.php', $run->findingsOf('phpstan'));
        self::assertStringNotContainsString('config/gates/src/Timing.php', $run->findingsOf('phpstan'));
    }

    public function testStaticAnalysisRunsAtMaxLevelWithoutABaseline(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/config/gates/phpstan.neon');

        self::assertStringContainsString('level: max', $configuration);
        self::assertStringNotContainsStringIgnoringCase('baseline', $configuration);
        self::assertStringNotContainsString('ignoreErrors', $configuration);
    }
}
