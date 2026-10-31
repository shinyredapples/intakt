<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpCsFixer\PlainFirstClassCallableFixer;
use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\FlagParameterRule;
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
#[CoversClass(FlagParameterRule::class)]
#[CoversClass(NoNestedTryRule::class)]
#[CoversClass(NoMockOptOutRule::class)]
#[CoversClass(ImportFormRule::class)]
#[CoversClass(PlainFirstClassCallableFixer::class)]
#[CoversClass(StatefulServiceResetRule::class)]
#[CoversClass(ContainerRegistration::class)]
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

    public function testAFullyQualifiedFirstClassCallableFailsTheCodingStandards(): void
    {
        $this->assertRejectedBy('cs', 'qualified-first-class-callable', 'src/Report.php: Intakt/plain_first_class_callable');
    }

    public function testAPlainFirstClassCallablePassesTheCodingStandards(): void
    {
        $this->assertAcceptedBy('cs', 'plain-first-class-callable');
    }

    public function testANamespacedFirstClassCallableIsLeftUnchangedByTheCodingStandards(): void
    {
        $this->assertAcceptedBy('cs', 'namespaced-first-class-callable');
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

    public function testAnImportAliasForAClassImportedTwiceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'duplicate-import-alias', 'src/Report.php:8 intakt.unneededImportAlias');
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

    public function testABooleanParameterOfAServiceMethodFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'flag-parameter-in-service', 'src/Mailer.php:9 intakt.flagParameter Parameter $urgent is a flag');
    }

    public function testABooleanParameterOfAClosureFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'flag-parameter-in-closure', 'src/Greeting.php:13 intakt.flagParameter Parameter $loud is a flag');
    }

    public function testABooleanParameterOfAFreeFunctionFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'flag-parameter-in-function', 'src/greet.php:7 intakt.flagParameter Parameter $loud is a flag');
    }

    public function testANullableBooleanParameterOfAReadonlyServiceConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'flag-parameter-in-constructor', 'src/Mailer.php:9 intakt.flagParameter Parameter $urgent is a flag');
    }

    public function testABooleanPropertyOfAReadonlyValueObjectExcludedFromTheContainerPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'readonly-value-object-boolean');
    }

    public function testABooleanAReadonlyValueObjectAssignsToAnUndeclaredPropertyFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-storing-undeclared-property', 'src/Toggle.php:12 intakt.flagParameter Parameter $enabled is a flag');
    }

    public function testABooleanAReadonlyValueObjectAssignsToAPropertyOfItsParentFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-storing-parent-property', 'src/Toggle.php:12 intakt.flagParameter Parameter $enabled is a flag');
    }

    public function testABooleanAReadonlyValueObjectConsumesInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-consuming-boolean', 'src/Label.php:14 intakt.flagParameter Parameter $uppercase is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectAlsoReadsInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-promoted-boolean', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAStoredBooleanAReadonlyValueObjectAlsoReadsInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-stored-boolean', 'src/Label.php:16 intakt.flagParameter Parameter $flag is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughItsPropertyInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-promoted-property', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAStoredBooleanAReadonlyValueObjectReadsThroughItsPropertyInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-stored-property', 'src/Label.php:16 intakt.flagParameter Parameter $flag is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAStringPropertyNameInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-property-by-string', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAComputedPropertyNameInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-property-by-expression', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAnAliasOfThisInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-property-through-alias', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughItsDefinedVariablesInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-defined-vars', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughEvalInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-eval', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughTheBacktraceInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-backtrace', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectPrintsThroughTheBacktraceInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-printing-backtrace', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughABacktraceCalledByCallableInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-backtrace-by-callable', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAnExceptionTraceInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-exception-trace', 'src/Label.php:15 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAnIncludedFileInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-include', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectHandsToItsParentConstructorFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-value-object-reading-in-parent-constructor', 'src/Label.php:12 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanOfAReadonlyClassOutsideTheSourceFolderFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'readonly-gate-class-with-flag', 'config/gates/src/Strictness.php:9 intakt.flagParameter Parameter $strict is a flag');
    }

    public function testAnEnumParameterPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'enum-parameter');
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
