<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ContractArrayTypeRule;
use PHPStan\Type\FileTypeMapper;
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

    public function testAContractMethodInAFolderBelowContractFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-nested-folder', 'src/Core/Sample/Contract/Event/Happened.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Event\Happened::labels() returns array<string, string>');
    }

    public function testAContractExposingAListOfStringsThroughATraitFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-trait-method', 'src/Core/Sample/Contract/Names.php:18 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Names::names() returns list<string>');
    }

    public function testAPromotedPropertyWhoseParameterTakesAnIntKeyedMapFailsAsItsParameter(): void
    {
        $this->assertRuleRejects('contract-array-promoted-normalized', 'src/Core/Sample/Contract/Batch.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Batch::__construct() parameter $items is typed array<int, DateTimeImmutable>');
    }

    public function testAPromotedPropertyNormalizedToAListOfObjectsPassesAsTheProperty(): void
    {
        self::assertStringNotContainsString('Property', $this->ruleFindingsOf('contract-array-promoted-normalized'));
    }

    public function testAPromotedPropertyAndItsDifferingParameterBothFail(): void
    {
        self::assertCount(2, explode("\n", $this->ruleFindingsOf('contract-array-promoted-both-refused')));
    }

    public function testADifferingParameterOfAPrivateConstructorPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-private-normalized');
    }

    public function testADifferingParameterOfAPublicConstructorFillingAPrivatePropertyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-private-promoted-input', 'src/Core/Sample/Contract/Kept.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Kept::__construct() parameter $items is typed array<int, stdClass>');
    }

    public function testAConstructorSpelledInUpperCaseIsJudgedLikeALowerCaseOne(): void
    {
        $this->assertRuleRejects('contract-array-upper-case-constructor', 'src/Core/Sample/Contract/Shouted.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Shouted::__CONSTRUCT() parameter $items is typed array<int, stdClass>');
    }

    public function testAParameterNamedLikeAPropertyAParentPromotesIsJudgedAsAParameter(): void
    {
        $this->assertRuleRejects('contract-array-shadowed-parent-property', 'src/Core/Sample/Contract/Counted.php:14 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Counted::__construct() parameter $items is typed list<int>');
    }

    public function testAnAllowedParameterOfAPublicConstructorPassesWhateverItsPrivatePropertyKeeps(): void
    {
        $this->assertRuleAccepts('contract-array-private-storage-wider');
    }

    public function testAPromotedPublicPropertyATraitBringsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-trait-promoted', 'src/Core/Sample/Contract/Carried.php:18 intakt.contractArrayType Property Intakt\Core\Sample\Contract\Carried::$items is typed array<int, DateTimeImmutable>');
    }

    public function testARefusedParameterOfAConstructorATraitFromAnotherFileBringsFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-trait-constructor-params', 'src/Core/Sample/Contract/Batch.php:9 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Batch::__construct() parameter $rejected is typed list<int>');
    }

    public function testAnAllowedParameterOfAConstructorATraitFromAnotherFileBringsPassesWhateverItsPropertyKeeps(): void
    {
        self::assertStringNotContainsString('$accepted', $this->ruleFindingsOf('contract-array-trait-constructor-params'));
    }

    public function testAConstructorOfATraitTheClassNamesInAnotherCaseIsJudgedByItsParamTagsNotItsProperties(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Core/Sample/Domain/Inputs.php', "<?php\nnamespace Intakt\\Core\\Sample\\Domain;\nuse stdClass;\ntrait Inputs {\n    /**\n     * @param list<int> \$rejected\n     * @param list<stdClass> \$accepted\n     */\n    public function __construct(\n        /** @var list<stdClass> */ protected array \$rejected,\n        /** @var array<int, stdClass> */ private array \$accepted,\n    ) {\n        \$this->rejected = array_map(static fn (int \$id): stdClass => (object) ['id' => \$id], \$rejected);\n    }\n}\n");
        $sandbox->write('src/Core/Sample/Contract/Batch.php', "<?php\nnamespace Intakt\\Core\\Sample\\Contract;\nuse Intakt\\Core\\Sample\\Domain\\inputs;\nfinal class Batch { use inputs; }\n");

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertStringContainsString('parameter $rejected is typed list<int>', $findings);
        self::assertStringNotContainsString('parameter $accepted', $findings);
    }

    public function testAContractInheritingAListOfStringsFromAParentOutsideContractFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('contract-array-inherited-method', 'src/Core/Sample/Contract/Named.php:9 intakt.contractArrayType Method Intakt\Core\Sample\Contract\Named::names() returns list<string>');
    }

    public function testAMethodInheritedFromAContractParentIsReportedOnlyInTheParent(): void
    {
        $findings = explode("\n", $this->ruleFindingsOf('contract-array-contract-parent'));

        self::assertCount(1, $findings);
        self::assertStringStartsWith('src/Core/Sample/Contract/ListedBase.php:12 intakt.contractArrayType', $findings[0]);
    }

    public function testAContractExtendingABuiltInExceptionPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-builtin-parent');
    }

    public function testTheCaseMethodsPhpGivesAnEnumPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('contract-array-enum-cases');
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
        return new ContractArrayTypeRule($root, self::getContainer()->getByType(FileTypeMapper::class));
    }
}
