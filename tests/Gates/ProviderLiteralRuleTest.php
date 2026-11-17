<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ProviderLiteralRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ProviderLiteralRule>
 */
#[CoversClass(ProviderLiteralRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class ProviderLiteralRuleTest extends PhpStanRuleTestCase
{
    // the fixtures' own contract, since a fixture cannot declare the repository's ProviderDescriptor a second time
    private const string CATALOG = 'Intakt\Sample\Contract\ProviderDescriptor';

    protected function ruleFor(string $root): ProviderLiteralRule
    {
        return new ProviderLiteralRule($root, self::createReflectionProvider(), self::CATALOG);
    }

    public function testAModelIdOutsideTheCatalogFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('model-id-outside-the-catalog', "src/ReviewerChoice.php:9 intakt.providerLiteral 'claude-sonnet-5-5' names a model");
    }

    public function testAProviderHostOutsideTheCatalogFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('provider-host-outside-the-catalog', "src/Egress.php:9 intakt.providerLiteral 'api.anthropic.com' names a provider host");
    }

    public function testAProviderHostInAUrlFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('provider-host-in-a-url', "src/TokenRefresh.php:9 intakt.providerLiteral 'auth.openai.com' names a provider host");
    }

    public function testAModelIdAfterAnInterpolationFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('model-id-in-an-interpolated-string', "src/Prompt.php:11 intakt.providerLiteral 'gpt-5-mini' names a model");
    }

    public function testModelIdsInOtherFormsFailStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('model-ids-in-other-forms');

        self::assertStringContainsString("src/Tier.php:9 intakt.providerLiteral 'claude-opus-5-5' names a model", $findings);
        self::assertStringContainsString("src/Ids.php:9 intakt.providerLiteral 'o4-mini' names a model", $findings);
        self::assertStringContainsString("src/Ids.php:11 intakt.providerLiteral 'gpt-oss-120b:novita' names a model", $findings);
        self::assertStringContainsString("src/Ids.php:19 intakt.providerLiteral 'claude-haiku-5-5' names a model", $findings);
        self::assertStringContainsString("src/Ids.php:23 intakt.providerLiteral 'Kimi-K3:deepinfra' names a model", $findings);
        self::assertStringContainsString("src/Ids.php:27 intakt.providerLiteral 'gemini-2.5-pro' names a model", $findings);
        self::assertStringContainsString("src/Ids.php:33 intakt.providerLiteral 'router.huggingface.co' names a provider host", $findings);
        self::assertStringContainsString("src/Names.php:11 intakt.providerLiteral 'CHATGPT.COM' names a provider host", $findings);
    }

    public function testAModelIdInAClassNamedLikeADescriptorThatIsNoneFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('model-id-in-a-class-that-only-looks-like-a-descriptor', "src/AnthropicDescriptor.php:9 intakt.providerLiteral 'claude-haiku-5-5' names a model");
    }

    public function testModelIdsAndHostsInTheCatalogPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('provider-literals-in-the-catalog');
    }

    public function testModelIdsOutsideSrcPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('provider-literals-outside-src');
    }

    public function testProviderWordsThatNameNoModelOrHostPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('provider-words-without-a-model-or-host');
    }
}
