<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// Tier selection reads a model's wait through its provider's own platform, so a pin that outlasts the account window keeps the model out; the clock is mocked and every order is forced, nothing sleeps.
#[CoversClass(TierPlatform::class)]
#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(ConfiguredTierResolver::class)]
#[UsesClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(PinnedProvider::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
final class TierPlatformPinnedProviderTest extends TestCase
{
    private const string PINNED = 'owner/model:router';

    private const Provider ROUTER = Provider::OpenAiCompatible;

    private MockClock $clock;

    private PinnedProviderCooldownWindows $pins;

    private AccountWindowsOnAClock $accounts;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19T12:00:00+00:00');
        $this->pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
        $this->accounts = new AccountWindowsOnAClock($this->clock, [self::ROUTER->value => [Account::DEFAULT]]);
    }

    public function testATierWhosePinnedModelCoolsWhileItsAccountWindowReadsFreeGoesToTheSubstituteAtTheFirstSelection(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), substitutes: true)->answering('demo', Tier::Strong);

        self::assertSame([0, Provider::Anthropic, 'other'], [$this->accounts->secondsUntilAnAccountIsFree(self::ROUTER), $answering->provider, $answering->model]);
    }

    // Forced order: the call is sent while the account is free, another call's limit then opens a window of 10 s, and the pinned 429 of 120 s read afterwards finds that window running, so the account store keeps the shorter one.
    public function testAPinnedLimitThatOutlastsARunningAccountWindowStillSendsTheTierToTheSubstituteOnceThatWindowEnds(): void
    {
        $router = $this->router(new FailingWhenRead(new RateLimitExceededException(120)));
        $answer = $router->invoke(self::PINNED, 'Review.');
        $this->accounts->coolDown(new Account(self::ROUTER, Account::DEFAULT), 10);
        $this->failureOf(static fn (): string => $answer->asText());

        $this->clock->sleep(11);
        $answering = $this->tiers($router, substitutes: true)->answering('demo', Tier::Strong);

        self::assertSame([0, 109, Provider::Anthropic], [$this->accounts->secondsUntilAnAccountIsFree(self::ROUTER), $this->pins->secondsUntilFree($this->pin()), $answering->provider]);
    }

    public function testATierWithoutSubstitutesWhosePinnedModelCoolsReportsThePinsWaitAndNotZero(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $out = $this->failureOf(fn (): object => $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), substitutes: false)->answering('demo', Tier::Strong));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame(120, $out->secondsUntilAnAccountIsFree);
    }

    public function testATierWhosePinnedModelIsFreeKeepsItsOwnModel(): void
    {
        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), substitutes: true)->answering('demo', Tier::Strong);

        self::assertSame([self::ROUTER, self::PINNED], [$answering->provider, $answering->model]);
    }

    private function router(PlatformInterface $account): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(self::ROUTER, [Account::DEFAULT => $account], $this->accounts, $this->clock, new NullLogger(), $this->pins);
    }

    private function tiers(AccountFailoverPlatform $router, bool $substitutes): TierPlatform
    {
        $strong = ['provider' => self::ROUTER->value, 'model' => self::PINNED];
        if ($substitutes) {
            $strong['substitutes'] = [['provider' => Provider::Anthropic->value, 'model' => 'other']];
        }

        $substitute = MockPlatformFactory::createPlatform('substitute');
        $platforms = new ServiceLocator([
            self::ROUTER->value => static fn (): PlatformInterface => $router,
            Provider::Anthropic->value => static fn (): PlatformInterface => $substitute,
        ]);

        return new TierPlatform(new ConfiguredTierResolver(['demo' => ['tiers' => ['strong' => $strong]]], []), $platforms, $this->accounts, new NullLogger());
    }

    private function pin(): PinnedProvider
    {
        return new PinnedProvider(new Account(self::ROUTER, Account::DEFAULT), 'router');
    }

    private function failureOf(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The call was expected to fail.');
    }
}
