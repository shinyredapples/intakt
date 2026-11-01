<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderOutage;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierProviderDisabled;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;

#[CoversClass(TierPlatform::class)]
#[CoversClass(EveryProviderOut::class)]
#[CoversClass(ProviderOutage::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(EveryKeyRejected::class)]
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

        $alpha = $platform->invoke($platform->answering('alpha', Tier::Strong), new TierRequest('review'))->asText();
        $beta = $platform->invoke($platform->answering('beta', Tier::Strong), new TierRequest('review'))->asText();

        self::assertSame('anthropic answered as big', $alpha);
        self::assertSame('openai answered as other', $beta);
    }

    public function testATierOnADisabledProviderIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(TierProviderDisabled::class);

        $this->tierPlatform(disabled: ['openai' => 'Provider "openai" is disabled: set OPENAI_API_KEY to enable it.'])->answering('beta', Tier::Strong);
    }

    public function testATierWhoseProviderHasAFreeAccountIsAnsweredByItsOwnModelEvenWithSubstitutes(): void
    {
        self::assertEquals(new ResolvedModel(Provider::Anthropic, 'big'), $this->tierPlatform()->answering('gamma', Tier::Strong));
    }

    public function testATierWhoseProviderIsOutGoesToItsFirstSubstituteAndTheSwitchIsLogged(): void
    {
        $logger = new BufferingLogger();

        $answering = $this->tierPlatform($this->out(Provider::Anthropic), $logger)->answering('gamma', Tier::Strong);

        self::assertEquals(new ResolvedModel(Provider::OpenAi, 'sub-openai'), $answering);
        self::assertSame([['info', 'Provider {own} is out, so the review goes to the substitute {substitute}.', ['own' => 'anthropic', 'substitute' => 'openai']]], $logger->cleanLogs());
    }

    public function testASubstituteWhoseEveryAccountIsOutIsSkippedForTheNextOne(): void
    {
        $answering = $this->tierPlatform($this->out(Provider::Anthropic, Provider::OpenAi))->answering('gamma', Tier::Strong);

        self::assertSame(Provider::OpenAiCompatible, $answering->provider);
    }

    public function testATierWithoutSubstitutesWhoseProviderIsOutWaitsForItAndIsNeverSubstituted(): void
    {
        try {
            $this->tierPlatform($this->out(Provider::Anthropic))->answering('alpha', Tier::Strong);
            self::fail('A provider whose every account is out was chosen.');
        } catch (EveryAccountCoolingDown $out) {
            self::assertSame(60, $out->secondsUntilAnAccountIsFree);
            self::assertStringContainsString('"anthropic"', $out->getMessage());
        }
    }

    public function testATierWhoseEveryProviderIsOutNamesEachProviderAndItsWait(): void
    {
        try {
            $this->tierPlatform($this->out(Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible))->answering('gamma', Tier::Strong);
            self::fail('A tier whose every provider is out was answered.');
        } catch (EveryProviderOut $out) {
            self::assertSame('Every provider of the tier is out: anthropic for 60 s, openai for 60 s, openai_compatible for 60 s.', $out->getMessage());
        }
    }

    public function testATierWhoseProviderIsOutAndWhoseEverySubstituteIsDisabledEndsAsAnOutageInsteadOfWaiting(): void
    {
        $platform = $this->tierPlatform($this->out(Provider::Anthropic), disabled: ['openai' => 'disabled', 'openai_compatible' => 'disabled']);

        try {
            $platform->answering('gamma', Tier::Strong);
            self::fail('A tier whose every usable provider is out was answered.');
        } catch (EveryProviderOut $out) {
            self::assertSame('Every provider of the tier is out: anthropic for 60 s.', $out->getMessage());
        }
    }

    // The reviewer's probe: no account of the primary is configured, so it is disabled, and a free substitute stands in.
    public function testATierWhosePrimaryIsDisabledIsAnsweredByAFreeSubstituteAndTheSwitchIsLogged(): void
    {
        $logger = new BufferingLogger();

        $answering = $this->tierPlatform(logger: $logger, disabled: ['anthropic' => 'Provider is disabled: no enabled accounts.'])->answering('gamma', Tier::Strong);

        self::assertEquals(new ResolvedModel(Provider::OpenAi, 'sub-openai'), $answering);
        self::assertSame([['info', 'Provider {own} is out, so the review goes to the substitute {substitute}.', ['own' => 'anthropic', 'substitute' => 'openai']]], $logger->cleanLogs());
    }

    public function testATierWhosePrimaryIsDisabledAndWhoseSubstitutesAreOutEndsAsAnOutageNamingThePrimaryAsDisabled(): void
    {
        $platform = $this->tierPlatform($this->out(Provider::OpenAi, Provider::OpenAiCompatible), disabled: ['anthropic' => 'disabled']);

        try {
            $platform->answering('gamma', Tier::Strong);
            self::fail('A tier whose every provider is out was answered.');
        } catch (EveryProviderOut $out) {
            self::assertSame('Every provider of the tier is out: anthropic disabled, openai for 60 s, openai_compatible for 60 s.', $out->getMessage());
        }
    }

    public function testATierWithoutSubstitutesWhoseEveryKeyWasRejectedIsTheRejectionAndNotAWait(): void
    {
        $this->expectException(EveryKeyRejected::class);

        $this->tierPlatform($this->rejected(Provider::Anthropic))->answering('alpha', Tier::Strong);
    }

    public function testASubstituteWhoseEveryKeyWasRejectedIsSkippedForTheNextOne(): void
    {
        $answering = $this->tierPlatform($this->rejected(Provider::Anthropic, Provider::OpenAi))->answering('gamma', Tier::Strong);

        self::assertSame(Provider::OpenAiCompatible, $answering->provider);
    }

    public function testATierWhoseEveryProviderIsOutNamesTheRejectedKeysInsteadOfAWait(): void
    {
        try {
            $this->tierPlatform($this->rejected(Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible))->answering('gamma', Tier::Strong);
            self::fail('A tier whose every provider rejected its keys was answered.');
        } catch (EveryProviderOut $out) {
            self::assertSame('Every provider of the tier is out: anthropic keys rejected, openai keys rejected, openai_compatible keys rejected.', $out->getMessage());
        }
    }

    public function testACallGoesToTheProviderOfTheModelItIsGiven(): void
    {
        $answer = $this->tierPlatform()->invoke(new ResolvedModel(Provider::OpenAi, 'sub-openai'), new TierRequest('review'))->asText();

        self::assertSame('openai answered as sub-openai', $answer);
    }

    private function out(Provider ...$providers): AccountCooldowns
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturnCallback(static fn (Provider $provider): int => in_array($provider, $providers, true) ? 60 : 0);

        return $cooldowns;
    }

    // Cooling down as well, because a rejected account cools down until its marker ends.
    private function rejected(Provider ...$providers): AccountCooldowns
    {
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturnCallback(static fn (Provider $provider): int => in_array($provider, $providers, true) ? 60 : 0);
        $cooldowns->method('isEveryAccountRejected')->willReturnCallback(static fn (Provider $provider): bool => in_array($provider, $providers, true));

        return $cooldowns;
    }

    /**
     * @param array<string, string> $disabled
     */
    private function tierPlatform(AccountCooldowns $cooldowns = new NoCooldowns(), ?BufferingLogger $logger = null, array $disabled = []): TierPlatform
    {
        $resolver = new ConfiguredTierResolver([
            'alpha' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big']]],
            'beta' => ['tiers' => ['strong' => ['provider' => 'openai', 'model' => 'other']]],
            'gamma' => ['tiers' => ['strong' => ['provider' => 'anthropic', 'model' => 'big', 'substitutes' => [
                ['provider' => 'openai', 'model' => 'sub-openai'],
                ['provider' => 'openai_compatible', 'model' => 'sub-compatible'],
            ]]]],
        ], $disabled);

        $platforms = new ServiceLocator([
            Provider::Anthropic->value => fn (): PlatformInterface => $this->platform(Provider::Anthropic),
            Provider::OpenAi->value => fn (): PlatformInterface => $this->platform(Provider::OpenAi),
        ]);

        return new TierPlatform($resolver, $platforms, $cooldowns, $logger ?? new NullLogger());
    }

    private function platform(Provider $provider): PlatformInterface
    {
        $answer = static fn (Model $model): string => sprintf('%s answered as %s', $provider->value, $model->getName());

        return MockPlatformFactory::createPlatform($answer, name: $provider->value);
    }
}
