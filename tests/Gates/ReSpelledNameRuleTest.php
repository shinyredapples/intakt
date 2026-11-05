<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ReSpelledNameRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
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

    protected function ruleFor(string $root): ReSpelledNameRule
    {
        return new ReSpelledNameRule($root, self::createReflectionProvider(), self::PROVIDER);
    }

    public function testServiceIdsInTheirIdsClassPassStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'service-ids-in-their-ids-class');
    }

    public function testARespelledServiceIdFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 're-spelled-service-id', "intakt.reSpelledName 'intakt.ai.platform.codex' re-spells a container id or parameter name");
    }

    public function testAProviderValueWrittenOutsideProviderFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('re-spelled-provider-value', "src/TierChoice.php:13 intakt.reSpelledName 'codex' re-spells a Provider case value");
    }

    public function testAProviderValueWrittenInConfigurationFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('re-spelled-provider-value-in-config', "config/packages/ai.php:5 intakt.reSpelledName 'codex' re-spells a Provider case value");
    }

    public function testIdsClassesProviderAndNamesDerivedFromThemPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('ids-class-and-provider');
    }

    public function testRespelledNamesInTestsAndGateConfigurationPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('re-spelled-names-outside-the-checked-folders');
    }
}
