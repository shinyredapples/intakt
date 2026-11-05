<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContainerRegistration;
use Intakt\Gates\PhpStan\FlagParameterRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<FlagParameterRule>
 */
#[CoversClass(FlagParameterRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(ContainerRegistration::class)]
final class FlagParameterRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): FlagParameterRule
    {
        return new FlagParameterRule($root, new ContainerRegistration($root));
    }

    public function testABooleanParameterOfAServiceMethodFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'flag-parameter-in-service', 'src/Mailer.php:9 intakt.flagParameter Parameter $urgent is a flag');
    }

    public function testABooleanParameterOfAClosureFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('flag-parameter-in-closure', 'src/Greeting.php:13 intakt.flagParameter Parameter $loud is a flag');
    }

    public function testABooleanParameterOfAFreeFunctionFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('flag-parameter-in-function', 'src/greet.php:7 intakt.flagParameter Parameter $loud is a flag');
    }

    public function testANullableBooleanParameterOfAReadonlyServiceConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('flag-parameter-in-constructor', 'src/Mailer.php:9 intakt.flagParameter Parameter $urgent is a flag');
    }

    public function testABooleanPropertyOfAReadonlyValueObjectExcludedFromTheContainerPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('readonly-value-object-boolean');
    }

    public function testABooleanAReadonlyValueObjectStoresInAPropertyOfAUsedTraitPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('readonly-value-object-storing-trait-property');
    }

    public function testABooleanAReadonlyValueObjectAssignsToAnUndeclaredPropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-storing-undeclared-property', 'src/Toggle.php:12 intakt.flagParameter Parameter $enabled is a flag');
    }

    public function testABooleanAReadonlyValueObjectAssignsToAPropertyOfItsParentFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-storing-parent-property', 'src/Toggle.php:12 intakt.flagParameter Parameter $enabled is a flag');
    }

    public function testABooleanAReadonlyValueObjectConsumesInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-consuming-boolean', 'src/Label.php:14 intakt.flagParameter Parameter $uppercase is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectAlsoReadsInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-promoted-boolean', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAStoredBooleanAReadonlyValueObjectAlsoReadsInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-stored-boolean', 'src/Label.php:16 intakt.flagParameter Parameter $flag is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughItsPropertyInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-promoted-property', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAStoredBooleanAReadonlyValueObjectReadsThroughItsPropertyInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-stored-property', 'src/Label.php:16 intakt.flagParameter Parameter $flag is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAStringPropertyNameInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-property-by-string', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAComputedPropertyNameInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-property-by-expression', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAnAliasOfThisInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-property-through-alias', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughItsDefinedVariablesInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-defined-vars', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughEvalInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-eval', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughTheBacktraceInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-backtrace', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectPrintsThroughTheBacktraceInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-printing-backtrace', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughABacktraceCalledByCallableInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-backtrace-by-callable', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAnExceptionTraceInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-exception-trace', 'src/Label.php:15 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectReadsThroughAnIncludedFileInItsConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-include', 'src/Label.php:14 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanAReadonlyValueObjectHandsToItsParentConstructorFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-value-object-reading-in-parent-constructor', 'src/Label.php:12 intakt.flagParameter Parameter $upper is a flag');
    }

    public function testAPromotedBooleanOfAReadonlyClassOutsideTheSourceFolderFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('readonly-gate-class-with-flag', 'config/gates/src/Strictness.php:9 intakt.flagParameter Parameter $strict is a flag');
    }

    public function testAnEnumParameterPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'enum-parameter');
    }
}
