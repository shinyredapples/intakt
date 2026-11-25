<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContractArrayTypeRule;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends PhpStanRuleTestCase<ContractArrayTypeRule>
 */
#[CoversClass(ContractArrayTypeRule::class)]
final class ContractArrayTypeRuleTest extends PhpStanRuleTestCase
{
    public function testAContractMethodReturningAnArrayShapeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-shape-return', 'src/Core/Sample/Contract/Summary.php:12 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Summary::totals() returns array{title: string, count: int}');
    }

    public function testTheFindingSaysHowToComply(): void
    {
        $this->assertRuleRejects('contract-array-shape-return', 'pass a readonly value object, or a list<Object> or array<string, Object> of objects or enums.');
    }

    public function testAContractMethodTakingAListOfStringsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-scalar-list-parameter', 'src/Core/Sample/Contract/Labels.php:12 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Labels::add() parameter $names is typed list<string>');
    }

    public function testAContractMethodTakingAnUntypedArrayFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-untyped-parameter', 'src/Core/Sample/Contract/Options.php:9 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Options::apply() parameter $options is typed array');
    }

    public function testAContractMethodReturningANullableArrayShapeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-nullable-shape-return', 'src/Core/Sample/Contract/Lookup.php:12 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Lookup::find() returns array{id: int}|null');
    }

    public function testAPromotedPublicPropertyTypedAsAnArrayShapeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-promoted-property', 'src/Core/Sample/Contract/Report.php:14 intakt.contractArrayType Property Intakt\Core\Sample\Contract\Report::$outcome is typed array{passed: bool, notes: string}');
    }

    public function testAPromotedPrivatePropertyOfAPublicConstructorFailsAsItsParameter(): void
    {
        $this->assertRuleRejects('contract-array-promoted-property', 'src/Core/Sample/Contract/Report.php:13 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Report::__construct() parameter $counts is typed array<string, int>');
    }

    public function testAPromotedPublicPropertyIsReportedOnlyAsTheProperty(): void
    {
        self::assertCount(2, explode("\n", $this->ruleFindingsOf('contract-array-promoted-property')));
    }

    public function testAContractMethodReturningAnIntKeyedMapOfObjectsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-int-keyed-map', 'src/Core/Sample/Contract/Directory.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Directory::byNumber() returns array<int, DateTimeImmutable>');
    }

    public function testAContractMethodReturningAMapOfObjectsWithAnyKeyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-int-keyed-map', 'src/Core/Sample/Contract/Directory.php:19 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Directory::byAnyKey() returns array<DateTimeImmutable>');
    }

    public function testAContractWithListsOfObjectsPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-object-list');
    }

    public function testAContractWithMapsOfEnumsKeyedByStringPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-string-map-of-enums');
    }

    public function testAPrivateMethodOfAContractReturningAnArrayShapePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-private-method');
    }

    public function testAPublicMethodOutsideAContractFolderReturningAnArrayShapePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-outside-contract');
    }

    public function testAPluginContractMethodReturningAListOfIntsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-plugin-scalars', 'src/Plugin/Sample/Contract/Checks.php:12 intakt.contractArrayType Method Intakt\Plugin\Sample\Contract\Checks::exitCodes() returns list<int>');
    }

    public function testAStaticEnumMethodInAFolderBelowContractFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-nested-folder', 'src/Core/Sample/Contract/Event/Happened.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Event\Happened::labels() returns array<string, string>');
    }

    public function testAMethodABuiltInInterfaceFixesPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-builtin-interface');
    }

    public function testAMethodOfAnOwnInterfaceReturningAnArrayShapeFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-own-interface', 'src/Core/Sample/Contract/Exported.php:16 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Exported::jsonSerialize() returns array{note: string}');
    }

    protected function ruleFor(string $root): ContractArrayTypeRule
    {
        return new ContractArrayTypeRule($root);
    }
}
