<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ReSpelledNameRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ReSpelledNameRule>
 */
#[CoversClass(ReSpelledNameRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ReSpelledNameRuleTest extends PhpStanRuleTestCase
{
    // the fixtures' own enum, since a fixture cannot declare the repository's Provider a second time
    private const string PROVIDER = 'Intakt\Sample\Contract\Provider';

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'ServiceIdsInTheirIdsClassPassStaticAnalysis' => ['service-ids-in-their-ids-class'],
            'ARespelledServiceIdFailsStaticAnalysis' => ['re-spelled-service-id', "src/PlatformLookup.php:11 intakt.reSpelledName 'intakt.ai.platform.codex' re-spells a container id or parameter name"],
        ], 'src/');
    }

    public function testAParameterReferenceToAContainerNameFailsStaticAnalysis(): void
    {
        self::assertStringContainsString("src/ReferenceLookup.php:11 intakt.reSpelledName '%intakt.ai.accounts%' re-spells a container id or parameter name", $this->ruleFindingsOf('re-spelled-reference-ids'));
    }

    public function testAServiceReferenceToAContainerIdFailsStaticAnalysis(): void
    {
        self::assertStringContainsString("src/ReferenceLookup.php:16 intakt.reSpelledName '@intakt.ai.platform' re-spells a container id or parameter name", $this->ruleFindingsOf('re-spelled-reference-ids'));
    }

    public function testAnInterpolatedServiceReferenceFailsStaticAnalysis(): void
    {
        self::assertStringContainsString("src/ReferenceLookup.php:21 intakt.reSpelledName '@intakt.ai.platform.' re-spells a container id or parameter name", $this->ruleFindingsOf('re-spelled-reference-ids'));
    }

    public function testAnEscapedPercentBeforeAContainerNamePassesStaticAnalysis(): void
    {
        self::assertStringNotContainsString('ReferenceLookup.php:26', $this->ruleFindingsOf('re-spelled-reference-ids'));
    }

    public function testReferenceFormsInTheirIdsClassPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('reference-ids-in-their-ids-class');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAProviderValueWrittenOutsideProviderFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('re-spelled-provider-value', "src/TierChoice.php:13 intakt.reSpelledName 'codex' re-spells a Provider case value");
    }

    public function testAProviderValueWrittenInConfigurationFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('re-spelled-provider-value-in-config', "config/packages/ai.php:5 intakt.reSpelledName 'codex' re-spells a Provider case value");
    }

    public function testAnInterpolatedServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('interpolated-service-id', "src/PlatformLookup.php:11 intakt.reSpelledName 'intakt.ai.platform.' re-spells a container id or parameter name");
    }

    public function testRespelledNamesInAnUnusedTraitFailStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('re-spelled-names-in-an-unused-trait');

        self::assertStringContainsString("src/Names.php:11 intakt.reSpelledName 'codex' re-spells a Provider case value", $findings);
        self::assertStringContainsString("src/Names.php:16 intakt.reSpelledName 'intakt.ai.platform.codex' re-spells a container id or parameter name", $findings);
    }

    public function testRespelledNamesInAnonymousClassesFailStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('re-spelled-names-in-anonymous-classes');

        self::assertStringContainsString("src/lookup.php:8 intakt.reSpelledName 'intakt.ai.platform.codex' re-spells a container id or parameter name", $findings);
        self::assertStringContainsString("src/Factory.php:12 intakt.reSpelledName 'intakt.anon' re-spells a container id or parameter name", $findings);
        self::assertStringContainsString("src/Factory.php:16 intakt.reSpelledName 'codex' re-spells a Provider case value", $findings);
    }

    public function testAServiceIdInATraitNamedLikeAnIdsClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('a-service-id-in-a-services-trait', "src/Contract/OtherServices.php:11 intakt.reSpelledName 'intakt.duplicate' re-spells a container id or parameter name");
    }

    public function testIdsClassesProviderAndNamesDerivedFromThemPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('ids-class-and-provider');
    }

    public function testRespelledNamesInTestsAndGateConfigurationPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('re-spelled-names-outside-the-checked-folders');
    }

    protected function ruleFor(string $root): ReSpelledNameRule
    {
        return new ReSpelledNameRule($root, self::createReflectionProvider(), self::PROVIDER);
    }
}
