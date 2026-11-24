<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredTierResolver::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ProjectNotConfigured::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(TierProviderDisabled::class)]
#[UsesClass(TierResolutionFailure::class)]
final class ConfiguredTierResolverTest extends TestCase
{
    public function testTwoProjectsResolveTheSameTierToDifferentModels(): void
    {
        $resolver = $this->resolver();

        $alpha = $resolver->resolve('alpha', Tier::Strong);
        $beta = $resolver->resolve('beta', Tier::Strong);

        self::assertSame([Provider::Anthropic, 'big'], [$alpha->provider, $alpha->model]);
        self::assertSame([Provider::OpenAi, 'other'], [$beta->provider, $beta->model]);
    }

    public function testProjectsAreListedInConfiguredOrder(): void
    {
        self::assertSame(['alpha', 'beta'], [...$this->resolver()->projects()]);
    }

    public function testATierPointingAtADisabledProviderNamesTheProviderMessage(): void
    {
        $resolver = $this->resolver(['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.']);

        try {
            $resolver->resolve('beta', Tier::Strong);
            self::fail('A disabled provider must not resolve.');
        } catch (TierProviderDisabled $failure) {
            self::assertSame('Project "beta" maps the "strong" tier to a disabled provider. Provider "openai" is disabled: set OPENAI_API_KEY to enable it.', $failure->getMessage());
        }
    }

    public function testATierOfAnEnabledProviderStillResolvesWhenAnotherProviderIsDisabled(): void
    {
        $resolved = $this->resolver(['openai' => 'off'])->resolve('alpha', Tier::Strong);

        self::assertSame(Provider::Anthropic, $resolved->provider);
    }

    public function testTheConfiguredModelOfATierOnADisabledProviderIsStillNamed(): void
    {
        $resolver = $this->resolver(['openai' => 'off']);

        self::assertEquals(new ResolvedModel(Provider::OpenAi, 'other'), $resolver->configured('beta', Tier::Strong));
        self::assertTrue($resolver->isDisabled(Provider::OpenAi));
    }

    public function testAProviderThatIsNotDisabledIsEnabled(): void
    {
        self::assertFalse($this->resolver(['openai' => 'off'])->isDisabled(Provider::Anthropic));
    }

    public function testAnUnmappedTierIsReported(): void
    {
        $this->expectException(TierNotMapped::class);

        $this->resolver()->resolve('alpha', Tier::Light);
    }

    public function testAnUnknownProjectIsReported(): void
    {
        $this->expectException(ProjectNotConfigured::class);

        $this->resolver()->resolve('gamma', Tier::Strong);
    }

    public function testATiersSubstitutesAreResolvedInConfiguredOrderAndDisabledProvidersAreLeftOut(): void
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big', 'substitutes' => [
            ['provider' => 'openai', 'model' => 'one'],
            ['provider' => 'claude_code', 'model' => 'two'],
            ['provider' => 'openai_compatible', 'model' => 'three'],
        ]]]]], ['claude_code' => 'Provider "claude_code" is disabled.']);

        $substitutes = $resolver->substitutes('alpha', Tier::Strong);

        self::assertEquals([new ResolvedModel(Provider::OpenAi, 'one'), new ResolvedModel(Provider::OpenAiCompatible, 'three')], $substitutes);
    }

    public function testATierWithoutConfiguredSubstitutesHasNone(): void
    {
        self::assertSame([], $this->resolver()->substitutes('alpha', Tier::Strong));
        self::assertFalse($this->resolver()->hasSubstitutes('alpha', Tier::Strong));
    }

    public function testATierWhoseEveryConfiguredSubstituteIsDisabledStillHasSubstitutes(): void
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big', 'substitutes' => [
            ['provider' => 'openai', 'model' => 'one'],
        ]]]]], ['openai' => 'Provider "openai" is disabled.']);

        self::assertTrue($resolver->hasSubstitutes('alpha', Tier::Strong));
    }

    /**
     * @param array<string, string> $disabled
     */
    private function resolver(array $disabled = []): ConfiguredTierResolver
    {
        return new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
            'beta' => ['tiers' => ['strong' => ['provider' => 'openai', 'model' => 'other']]],
        ], $disabled);
    }
}
