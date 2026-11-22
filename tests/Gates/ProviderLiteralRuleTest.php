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

    public function testProviderWordsInTheFormsOfModelIdsPassStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('model-ids-in-other-forms');

        // the router URL above them is still found, so the fixture was analyzed and the absent lines are no vacuous pass
        self::assertStringContainsString('src/Ids.php:33 ', $findings);

        foreach (range(36, 47) as $line) {
            self::assertStringNotContainsString(sprintf('src/Ids.php:%d ', $line), $findings);
        }
    }

    public function testDatedAndPreviewReasoningModelIdsFailStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('reasoning-model-ids-with-suffixes');

        foreach ([15 => 'o3', 16 => 'o3-2025-04-16', 17 => 'o1-preview', 18 => 'o4-mini-2025-04-16', 19 => 'o3-pro-2025-06-10', 20 => 'o3-deep-research', 21 => 'O1-MINI', 22 => 'o3', 23 => 'o1', 24 => 'o4', 25 => 'o3', 26 => 'o3', 27 => 'o3:online', 28 => 'o3', 29 => 'o3', 30 => 'o1'] as $line => $id) {
            self::assertStringContainsString(sprintf("src/Reasoning.php:%d intakt.providerLiteral '%s' names a model", $line, $id), $findings);
        }

        self::assertStringNotContainsString('src/Reasoning.php:39', $findings);
    }

    public function testEmbeddingCodexAndDeepSeekModelIdsFailStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('model-ids-of-more-families');

        foreach ([15 => 'text-embedding-3-small', 16 => 'codex-mini-latest', 17 => 'deepseek-chat', 18 => 'deepseek-reasoner', 19 => 'deepseek-coder', 20 => 'text-embedding-ada-002'] as $line => $id) {
            self::assertStringContainsString(sprintf("src/Families.php:%d intakt.providerLiteral '%s' names a model", $line, $id), $findings);
        }
    }

    public function testReasoningSeriesWordsInProsePassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('reasoning-series-words-in-prose');
    }

    public function testProviderHostsAreFoundAtTheirBoundariesOnly(): void
    {
        $findings = $this->ruleFindingsOf('provider-hosts-at-their-boundaries');

        self::assertStringContainsString("src/Hosts.php:15 intakt.providerLiteral 'API.OPENAI.COM' names a provider host", $findings);
        self::assertStringContainsString("intakt.providerLiteral 'router.huggingface.co' names a provider host", $findings);
        self::assertStringNotContainsString('src/Hosts.php:16', $findings);
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

    protected function ruleFor(string $root): ProviderLiteralRule
    {
        return new ProviderLiteralRule($root, self::createReflectionProvider(), self::CATALOG);
    }
}
