<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderOutage;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierRequest;
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
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Model;
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
#[UsesClass(TierRequest::class)]
#[UsesClass(EveryProviderOut::class)]
#[UsesClass(ProviderOutage::class)]
final class TierPlatformPinnedProviderTest extends TestCase
{
    private const string PINNED = 'owner/model:router';

    private const Provider ROUTER = Provider::OpenAiCompatible;

    private const array SUBSTITUTE = ['provider' => Provider::Anthropic->value, 'model' => 'other'];

    private const array FALLBACK = ['provider' => Provider::OpenAiCompatible->value, 'model' => 'owner/model:other-router'];

    private const array ALIAS = ['provider' => Provider::OpenAiCompatible->value, 'model' => 'owner/model'];

    private const array OTHER_MODEL = ['provider' => Provider::OpenAiCompatible->value, 'model' => 'owner/other:free-router'];

    private MockClock $clock;

    private ?LoggerInterface $logger = null;

    private PinnedProviderCooldownWindows $pins;

    private AccountWindowsOnAClock $accounts;

    public function testATierWhosePinnedModelCoolsWhileItsAccountWindowReadsFreeGoesToTheSubstituteAtTheFirstSelection(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::SUBSTITUTE])->answering('demo', Tier::Strong);

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
        $answering = $this->tiers($router, [self::SUBSTITUTE])->answering('demo', Tier::Strong);

        self::assertSame([0, 109, Provider::Anthropic], [$this->accounts->secondsUntilAnAccountIsFree(self::ROUTER), $this->pins->secondsUntilFree($this->pin()), $answering->provider]);
    }

    public function testATierWithoutSubstitutesWhosePinnedModelCoolsReportsThePinsWaitAndNotZero(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $out = $this->failureOf(fn (): object => $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [])->answering('demo', Tier::Strong));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame(120, $out->secondsUntilAnAccountIsFree);
    }

    public function testATierWhosePinnedModelIsFreeKeepsItsOwnModel(): void
    {
        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::SUBSTITUTE])->answering('demo', Tier::Strong);

        self::assertSame([self::ROUTER, self::PINNED], [$answering->provider, $answering->model]);
    }

    // Forced order: the account window of 1 s is still running when selection starts, and the provider's platform is built 2 s later, so a provider-wide read taken before it would still see the window while the model's own read finds it over.
    public function testAnAccountWindowThatEndsWhileThePlatformIsBuiltKeepsTheTierOnItsOwnModel(): void
    {
        $this->accounts->coolDown(new Account(self::ROUTER, Account::DEFAULT), 1);
        $router = $this->router(MockPlatformFactory::createPlatform('first'));
        $clock = $this->clock;

        $answering = $this->tiers($router, [self::SUBSTITUTE], built: static function () use ($clock, $router): PlatformInterface {
            $clock->sleep(2);

            return $router;
        })->answering('demo', Tier::Strong);

        self::assertSame([self::ROUTER, self::PINNED], [$answering->provider, $answering->model]);
    }

    public function testAPinnedFallbackOnTheTiersOwnProviderAnswersWhileThePinCools(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::FALLBACK, self::SUBSTITUTE])->answering('demo', Tier::Strong);

        self::assertSame([self::ROUTER, self::FALLBACK['model']], [$answering->provider, $answering->model]);
    }

    public function testWhenThePinnedFallbackCoolsTooTheTierGoesToTheNextProvider(): void
    {
        $this->pins->coolDown($this->pin(), 120);
        $this->pins->coolDown(new PinnedProvider(new Account(self::ROUTER, Account::DEFAULT), 'other-router'), 30);

        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::FALLBACK, self::SUBSTITUTE])->answering('demo', Tier::Strong);

        self::assertSame([Provider::Anthropic, 'other'], [$answering->provider, $answering->model]);
    }

    public function testWhenTheOwnModelAndItsPinnedFallbackCoolTheOutageNamesTheProviderOnce(): void
    {
        $this->pins->coolDown($this->pin(), 120);
        $this->pins->coolDown(new PinnedProvider(new Account(self::ROUTER, Account::DEFAULT), 'other-router'), 30);

        $out = $this->failureOf(fn (): object => $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::FALLBACK])->answering('demo', Tier::Strong));

        self::assertInstanceOf(EveryProviderOut::class, $out);
        self::assertSame('Every provider of the tier is out: openai_compatible for 30 s.', $out->getMessage());
    }

    public function testAFreeFallbackOnTheOwnProviderWinsOverAnEarlierSubstituteOnAnotherProvider(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::SUBSTITUTE, self::FALLBACK])->answering('demo', Tier::Strong);

        self::assertSame([self::ROUTER, self::FALLBACK['model']], [$answering->provider, $answering->model]);
    }

    public function testAnUnpinnedAliasOfTheOwnModelKeepsItsListedPlaceAfterAnEarlierSubstitute(): void
    {
        $this->pins->coolDown($this->pin(), 120);

        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::SUBSTITUTE, self::ALIAS])->answering('demo', Tier::Strong);

        self::assertSame([Provider::Anthropic, 'other'], [$answering->provider, $answering->model]);
    }

    public function testAnotherModelOnTheOwnProviderIsRecordedAsASubstituteAndNotAsAFallback(): void
    {
        $this->pins->coolDown($this->pin(), 120);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $this->logger = $logger;
        $answering = $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::OTHER_MODEL])->answering('demo', Tier::Strong);

        self::assertSame(['owner/other:free-router', ['Provider {own} is out, so the review goes to the substitute {substitute}.']], [$answering->model, $logger->messages]);
    }

    public function testWhenEveryPinCoolsTheReportedWaitIsTheShortestOne(): void
    {
        $this->pins->coolDown($this->pin(), 120);
        $this->pins->coolDown(new PinnedProvider(new Account(self::ROUTER, Account::DEFAULT), 'other-router'), 30);
        $this->pins->coolDown(new PinnedProvider(new Account(self::ROUTER, Account::DEFAULT), 'free-router'), 120);

        $out = $this->failureOf(fn (): object => $this->tiers($this->router(MockPlatformFactory::createPlatform('first')), [self::OTHER_MODEL, self::FALLBACK])->answering('demo', Tier::Strong));

        self::assertInstanceOf(EveryProviderOut::class, $out);
        self::assertSame(30, $out->outages[0]->secondsUntilFree);
    }

    // The whole call, because selection alone does not show that the account still serves the fallback after the pinned 429 (issue #2825).
    public function testAPinned429IsFollowedByTheCallCompletingThroughThePinnedFallback(): void
    {
        $sent = [];
        $router = MockPlatformFactory::createPlatform(static function (string|Model $model) use (&$sent): string {
            $name = $model instanceof Model ? $model->getName() : $model;
            $sent[] = $name;

            return self::PINNED === $name ? throw new RateLimitExceededException(60) : 'reviewed by the fallback';
        });
        $tiers = $this->tiers($this->router($router), [self::FALLBACK, self::SUBSTITUTE]);

        $limited = $this->failureOf(fn (): object => $tiers->invoke($tiers->answering('demo', Tier::Strong), TierRequest::of('Review.')));
        $answer = $tiers->invoke($tiers->answering('demo', Tier::Strong), TierRequest::of('Review.'))->asText();

        self::assertInstanceOf(EveryAccountCoolingDown::class, $limited);
        self::assertSame('reviewed by the fallback', $answer);
        self::assertSame([self::PINNED, self::FALLBACK['model']], $sent);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19T12:00:00+00:00');
        $this->pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
        $this->accounts = new AccountWindowsOnAClock($this->clock, [self::ROUTER->value => [Account::DEFAULT]]);
    }

    private function pin(): PinnedProvider
    {
        return new PinnedProvider(new Account(self::ROUTER, Account::DEFAULT), 'router');
    }

    private function router(PlatformInterface $account): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(self::ROUTER, [Account::DEFAULT => $account], $this->accounts, $this->clock, new NullLogger(), $this->pins);
    }

    /**
     * @param list<array{provider: string, model: non-empty-string}> $substitutes
     * @param (Closure(): PlatformInterface)|null                    $built
     */
    private function tiers(AccountFailoverPlatform $router, array $substitutes, ?Closure $built = null): TierPlatform
    {
        $strong = ['provider' => self::ROUTER->value, 'model' => self::PINNED];
        if ([] !== $substitutes) {
            $strong['substitutes'] = $substitutes;
        }

        $substitute = MockPlatformFactory::createPlatform('substitute');
        $platforms = new ServiceLocator([
            self::ROUTER->value => $built ?? static fn (): PlatformInterface => $router,
            Provider::Anthropic->value => static fn (): PlatformInterface => $substitute,
        ]);

        return new TierPlatform(new ConfiguredTierResolver(['demo' => ['tiers' => ['strong' => $strong]]], []), $platforms, $this->accounts, $this->logger ?? new NullLogger());
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
