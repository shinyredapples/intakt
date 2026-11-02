<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AnthropicBridgeCatalog;
use Intakt\Core\Ai\Infrastructure\OpenAiBridgeCatalog;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Capability;

#[CoversClass(ProjectModelCatalog::class)]
#[UsesClass(Provider::class)]
#[UsesClass(AnthropicBridgeCatalog::class)]
#[UsesClass(OpenAiBridgeCatalog::class)]
final class ProjectModelCatalogTest extends TestCase
{
    public function testAModelTheBridgeDoesNotKnowIsAddedWithTheBridgeModelClass(): void
    {
        $models = $this->catalog()->models(Provider::Anthropic);

        self::assertSame(['claude-next'], array_keys($models));
        self::assertSame(Claude::class, $models['claude-next']['class']);
        self::assertContains(Capability::OUTPUT_STRUCTURED, $models['claude-next']['capabilities']);
    }

    public function testAModelTheBridgeKnowsKeepsTheBridgeEntry(): void
    {
        $models = $this->catalog()->models(Provider::Anthropic);

        self::assertArrayNotHasKey('claude-3-5-haiku-latest', $models);
    }

    public function testEachProviderGetsOnlyItsOwnModelsOnce(): void
    {
        self::assertSame(['other'], array_keys($this->catalog()->models(Provider::OpenAi)));
        self::assertSame(Gpt::class, $this->catalog()->models(Provider::OpenAi)['other']['class']);
    }

    public function testAnOpenAiCompatibleModelIsACompletionsModel(): void
    {
        $catalog = new ProjectModelCatalog(['gamma' => ['tiers' => ['light' => ['provider' => 'openai_compatible', 'model' => 'local']]]]);

        self::assertSame(CompletionsModel::class, $catalog->models(Provider::OpenAiCompatible)['local']['class']);
    }

    public function testASubscriptionModelIsAddedEvenWhenTheBridgeKnowsItSoItSupportsStructuredOutput(): void
    {
        $catalog = new ProjectModelCatalog(['gamma' => ['tiers' => ['strong' => ['provider' => 'claude_code', 'model' => 'sonnet']]]]);

        $models = $catalog->models(Provider::ClaudeCode);

        self::assertSame(ClaudeCode::class, $models['sonnet']['class']);
        self::assertContains(Capability::OUTPUT_STRUCTURED, $models['sonnet']['capabilities']);
    }

    public function testACodexModelIsAddedEvenWhenTheBridgeKnowsItSoItSupportsStructuredOutput(): void
    {
        $catalog = new ProjectModelCatalog(['gamma' => ['tiers' => ['strong' => ['provider' => 'codex', 'model' => 'gpt-5.3-codex']]]]);

        $models = $catalog->models(Provider::Codex);

        self::assertSame(Codex::class, $models['gpt-5.3-codex']['class']);
        self::assertContains(Capability::OUTPUT_STRUCTURED, $models['gpt-5.3-codex']['capabilities']);
    }

    public function testAProviderNoProjectMapsGetsNoModels(): void
    {
        self::assertSame([], $this->catalog()->models(Provider::OpenAiCompatible));
    }

    private function catalog(): ProjectModelCatalog
    {
        return new ProjectModelCatalog([
            'alpha' => ['tiers' => [
                'strong' => ['provider' => 'anthropic', 'model' => 'claude-next'],
                'light' => ['provider' => 'anthropic', 'model' => 'claude-3-5-haiku-latest'],
            ]],
            'beta' => ['tiers' => [
                'strong' => ['provider' => 'openai', 'model' => 'other'],
                'light' => ['provider' => 'openai', 'model' => 'other'],
            ]],
        ]);
    }
}
