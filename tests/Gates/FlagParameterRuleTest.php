<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\FlagParameterRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(FlagParameterRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(ContainerRegistration::class)]
final class FlagParameterRuleTest extends SandboxTestCase
{
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

    public function testABooleanAReadonlyValueObjectStoresInAPropertyOfAUsedTraitPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'readonly-value-object-storing-trait-property');
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
}
