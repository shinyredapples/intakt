<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(TierRoutedPlatform::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierRequest::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
final class TierRoutedPlatformTest extends TestCase
{
    public function testARoundStaysOnTheTiersOwnProviderWhileEveryAccountOfItIsOut(): void
    {
        $routed = $this->routedWithAnthropicOutAfter(0);

        $answer = $routed->invoke('strong', 'review')->asText();

        self::assertSame('anthropic', $answer);
    }

    public function testAReviewIsNotContinuedOnAnotherProviderWhenItsOwnGoesOutBetweenRounds(): void
    {
        $routed = $this->routedWithAnthropicOutAfter(1);

        $first = $routed->invoke('strong', 'first call')->asText();
        $continuation = $routed->invoke('strong', 'continuation')->asText();

        self::assertSame(['anthropic', 'anthropic'], [$first, $continuation]);
    }

    private function routedWithAnthropicOutAfter(int $freeReads): TierRoutedPlatform
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => ['strong' => [
            'provider' => 'anthropic', 'model' => 'big',
            'substitutes' => [['provider' => 'openai', 'model' => 'other'], ['provider' => 'claude_code', 'model' => 'sonnet']],
        ]]]], []);
        $reads = 0;
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilAnAccountIsFree')->willReturnCallback(static function (Provider $provider) use (&$reads, $freeReads): int {
            return Provider::Anthropic === $provider && ++$reads > $freeReads ? 60 : 0;
        });
        $platforms = new ServiceLocator([
            'anthropic' => static fn (): PlatformInterface => MockPlatformFactory::createPlatform(static fn (): string => 'anthropic'),
            'openai' => static fn (): PlatformInterface => MockPlatformFactory::createPlatform(static fn (): string => 'openai'),
            'claude_code' => static fn (): PlatformInterface => MockPlatformFactory::createPlatform(static fn (): string => 'claude_code'),
        ]);

        return new TierRoutedPlatform(new TierPlatform($resolver, $platforms, $cooldowns, new NullLogger()), $resolver, 'alpha');
    }
}
