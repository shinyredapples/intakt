<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierModelRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;

#[CoversClass(TierModelRouter::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(TierProviderDisabled::class)]
#[UsesClass(TierResolutionFailure::class)]
final class TierModelRouterTest extends TestCase
{
    public function testEachProjectSendsTheSameTierToItsOwnProviderAndModel(): void
    {
        $platform = $this->platform();

        $alpha = $platform->invoke('strong', 'review', ['project' => 'alpha'])->asText();
        $beta = $platform->invoke('strong', 'review', ['project' => 'beta'])->asText();

        self::assertSame('anthropic answered as big with []', $alpha);
        self::assertSame('openai answered as other with []', $beta);
    }

    public function testTheProjectOptionDoesNotReachTheProvider(): void
    {
        $answer = $this->platform()->invoke('strong', 'review', ['project' => 'alpha', 'temperature' => 0.0])->asText();

        self::assertSame('anthropic answered as big with [temperature]', $answer);
    }

    public function testATierOnADisabledProviderIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(TierProviderDisabled::class);

        $this->platform(['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.'])->invoke('strong', 'review', ['project' => 'beta']);
    }

    public function testAModelNameThatIsNoTierIsRefused(): void
    {
        $this->expectExceptionObject(new ModelNotFoundException('"big" is not a capability tier: use strong, standard or light.'));

        $this->platform()->invoke('big', 'review', ['project' => 'alpha']);
    }

    public function testACallWithoutAProjectIsRefused(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('A tier call needs the project as the "project" option.'));

        $this->platform()->invoke('strong', 'review');
    }

    public function testAProviderMissingFromThePlatformIsRefused(): void
    {
        $platform = new Platform([$this->provider(Provider::Anthropic)], $this->router([]));

        $this->expectExceptionObject(new ModelNotFoundException('The platform has no provider "openai".'));

        $platform->invoke('strong', 'review', ['project' => 'beta']);
    }

    /**
     * @param array<string, string> $disabled
     */
    private function platform(array $disabled = []): Platform
    {
        return new Platform([$this->provider(Provider::Anthropic), $this->provider(Provider::OpenAi)], $this->router($disabled));
    }

    private function provider(Provider $provider): ProviderInterface
    {
        $answer = static fn (Model $model, mixed $input, array $options): string => sprintf('%s answered as %s with [%s]', $provider->value, $model->getName(), implode(',', array_keys($options)));

        return MockPlatformFactory::createProvider($answer, name: $provider->value);
    }

    /**
     * @param array<string, string> $disabled
     */
    private function router(array $disabled): TierModelRouter
    {
        return new TierModelRouter(new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
            'beta' => ['tiers' => ['strong' => ['provider' => 'openai', 'model' => 'other']]],
        ], $disabled));
    }
}
