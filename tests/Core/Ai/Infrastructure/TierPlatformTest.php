<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ProviderFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(TierPlatform::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ProviderFailoverPlatform::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(TierProviderDisabled::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(TierResolutionFailure::class)]
final class TierPlatformTest extends TestCase
{
    public function testEachProjectSendsTheSameTierToItsOwnProviderAndModel(): void
    {
        $platform = $this->tierPlatform();

        $alpha = $platform->invoke('alpha', Tier::Strong, new TierRequest('review'))->asText();
        $beta = $platform->invoke('beta', Tier::Strong, new TierRequest('review'))->asText();

        self::assertSame('anthropic answered as big', $alpha);
        self::assertSame('openai answered as other', $beta);
    }

    public function testATierOnADisabledProviderIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(TierProviderDisabled::class);

        (void) $this->tierPlatform(['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.'])->invoke('beta', Tier::Strong, new TierRequest('review'));
    }

    /**
     * @param array<string, string> $disabled
     */
    private function tierPlatform(array $disabled = []): TierPlatform
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
            'beta' => ['tiers' => ['strong' => ['provider' => 'openai', 'model' => 'other']]],
        ], $disabled);

        $platforms = new ServiceLocator([
            Provider::Anthropic->value => fn (): PlatformInterface => $this->platform(Provider::Anthropic),
            Provider::OpenAi->value => fn (): PlatformInterface => $this->platform(Provider::OpenAi),
        ]);

        return new TierPlatform($resolver, new ProviderFailoverPlatform($platforms, new NoCooldowns(), new NativeClock(), new NullLogger()));
    }

    private function platform(Provider $provider): PlatformInterface
    {
        $answer = static fn (Model $model): string => sprintf('%s answered as %s', $provider->value, $model->getName());

        return MockPlatformFactory::createPlatform($answer, name: $provider->value);
    }
}
