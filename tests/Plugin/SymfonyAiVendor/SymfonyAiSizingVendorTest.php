<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizeTier;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Build\Infrastructure\SizingAgent;
use Intakt\Core\Build\Infrastructure\SizingPrompt;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiSizingVendor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\TierRoutedPlatform;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

#[CoversClass(SymfonyAiSizingVendor::class)]
#[UsesClass(SizingAgent::class)]
#[UsesClass(SizingPrompt::class)]
#[UsesClass(SizingVerdict::class)]
#[UsesClass(SizingRequest::class)]
#[UsesClass(SizeTier::class)]
#[UsesClass(ReadinessGap::class)]
#[UsesClass(Fence::class)]
#[UsesClass(UntrustedSource::class)]
#[UsesClass(Tier::class)]
#[UsesClass(Provider::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierPlatform::class)]
#[UsesClass(TierRoutedPlatform::class)]
#[UsesClass(UsageLedger::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(ProviderRateLimited::class)]
#[UsesClass(VendorAccountLimit::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
final class SymfonyAiSizingVendorTest extends KernelTestCase
{
    private const string READY = '{"ready":true,"missing":[],"size":"small","estimated_lines":40,"reason":"Fine."}';

    public function testTheSizingCallsTokensAreChargedToTheTask(): void
    {
        $ledger = new InMemoryLedger();

        $verdict = $this->vendor(static fn (): string => self::READY, $ledger)->size($this->request());

        self::assertSame(SizeTier::Small, $verdict?->size);
        self::assertSame(['started', 'settled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(['alpha', '7', 'standard'], [$ledger->payloads()[0]['project'], $ledger->payloads()[0]['task'], $ledger->payloads()[0]['tier']]);
        self::assertSame([LedgerEventKind::UsageRecorded, LedgerEventKind::UsageRecorded], $ledger->kinds());
    }

    public function testAnInvalidAnswerIsNoVerdictButItsCallIsStillSettledAgainstTheTask(): void
    {
        $ledger = new InMemoryLedger();

        $verdict = $this->vendor(static fn (): string => '{"ready": tru', $ledger)->size($this->request());

        self::assertNull($verdict);
        self::assertSame(['started', 'settled'], array_column($ledger->payloads(), 'phase'));
    }

    public function testAFailedSizingCallIsATypedProviderErrorAndLeftUnsettled(): void
    {
        $ledger = new InMemoryLedger();
        $limited = static fn (): never => throw new RateLimitExceededException(30);

        try {
            $this->vendor($limited, $ledger)->size($this->request());
            self::fail('A failed sizing call must stay an error, never a verdict.');
        } catch (ProviderRateLimited $rateLimited) {
            self::assertSame(30, $rateLimited->retryAfterSeconds);
        }

        self::assertSame(['started', 'unsettled'], array_column($ledger->payloads(), 'phase'));
        self::assertSame(RateLimitExceededException::class, $ledger->payloads()[1]['failure']);
    }

    private function vendor(Closure $answer, InMemoryLedger $ledger): SymfonyAiSizingVendor
    {
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => [
            'standard' => ['provider' => 'anthropic', 'model' => 'middle'],
        ]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger($ledger, new MockClock('2026-11-28 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiSizingVendor(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), new SizingAgent(new SizingPrompt(new Fence())), $usage, $limit);
    }

    private function request(): SizingRequest
    {
        return new SizingRequest('alpha', 7, 'Add a cart', 'Users can add items. Done when the cart page lists them.');
    }
}
