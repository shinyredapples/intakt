<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// The accounts never cool down here unless a test says so, so the pinned windows on the mocked clock alone decide.
#[CoversClass(AccountFailoverPlatform::class)]
#[CoversClass(AccountPlatform::class)]
#[CoversClass(ModelWaits::class)]
#[UsesClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(PinnedProvider::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
final class PinnedProviderCooldownTest extends TestCase
{
    private const string PINNED = 'moonshotai/Kimi-K3.1-Pro:deepinfra';

    private const string FALLBACK = 'moonshotai/Kimi-K3.1-Pro:together';

    private const string UNPINNED = 'moonshotai/Kimi-K3.1-Pro';

    private MockClock $clock;

    private PinnedProviderCooldownWindows $pins;

    public function testA429FromAPinnedProviderCoolsItsPinForTheRetryAfterAndTheCallGoesToTheNextAccount(): void
    {
        $answer = $this->accounts($this->answering(static fn (): never => throw new RateLimitExceededException(120)), $this->answering('second'))->invoke(self::PINNED, 'Review.');

        self::assertSame('second', $answer->asText());
        self::assertSame(120, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT)));
        self::assertSame(0, $this->pins->secondsUntilFree($this->deepinfra('team')));
    }

    // Issue #2861: the 429 cools the pin and ends the call after its one request, with the pin's wait; the account is not cooled, so another router provider on it is called at once, while the pin gets no request until its window ends.
    public function testAPinned429EndsTheCallAfterOneRequestAndLeavesTheAccountFreeForAnotherRouterProvider(): void
    {
        $requests = [];
        $router = $this->answering(static function (string|Model $model) use (&$requests): string {
            $name = $model instanceof Model ? $model->getName() : $model;
            $requests[] = $name;

            return self::PINNED === $name ? throw new RateLimitExceededException(60) : 'fallback';
        });
        $accounts = new AccountWindowsOnAClock($this->clock, [Provider::OpenAiCompatible->value => [Account::DEFAULT]]);
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $router], $accounts, $this->clock, new NullLogger(), $this->pins);

        $limited = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));
        $whileCooling = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));
        $fallback = $platform->invoke(self::FALLBACK, 'Review.')->asText();

        self::assertInstanceOf(EveryAccountCoolingDown::class, $limited);
        self::assertInstanceOf(EveryAccountCoolingDown::class, $whileCooling);
        self::assertSame([60, 0, 'fallback'], [$limited->secondsUntilAnAccountIsFree, $accounts->secondsUntilFree(new Account(Provider::OpenAiCompatible, Account::DEFAULT)), $fallback]);
        self::assertSame([self::PINNED, self::FALLBACK], $requests);
    }

    public function testA429WithoutARetryAfterCoolsThePinForTheDefaultMinute(): void
    {
        $this->accounts($this->answering(static fn (): never => throw new RateLimitExceededException()), $this->answering('second'))->invoke(self::PINNED, 'Review.')->asText();

        self::assertSame(60, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT)));
    }

    public function testA429WithAResetOfZeroCoolsThePinForASecondAndTheNextCallSendsNothing(): void
    {
        $requests = 0;
        $router = $this->answering(static function () use (&$requests): never {
            ++$requests;

            throw new RateLimitExceededException(0);
        });
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $router], new NoCooldowns(), $this->clock, new NullLogger(), $this->pins);

        $limited = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));
        $whileCooling = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $limited);
        self::assertInstanceOf(EveryAccountCoolingDown::class, $whileCooling);
        self::assertSame([1, 1], [$limited->secondsUntilAnAccountIsFree, $requests]);
    }

    public function testA429ReadFromTheAnswerCoolsThePinToo(): void
    {
        $answer = $this->accounts(new FailingWhenRead(new RateLimitExceededException(90)), $this->answering('second'))->invoke(self::PINNED, 'Review.');

        $this->failureOf(static fn (): string => $answer->asText());

        self::assertSame(90, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT)));
    }

    public function testAnAccountWhosePinCoolsIsSkippedWithoutARequestAndUsedAgainOnceThePinHasEnded(): void
    {
        $this->pins->coolDown($this->deepinfra(Account::DEFAULT), 300);
        $requests = 0;
        $default = $this->answering(static function () use (&$requests): string {
            ++$requests;

            return 'first';
        });
        $platform = $this->accounts($default, $this->answering('second'));

        $during = $platform->invoke(self::PINNED, 'Review.')->asText();
        $requestsDuring = $requests;
        $this->clock->sleep(300);
        $after = $platform->invoke(self::PINNED, 'Review.')->asText();

        self::assertSame(['second', 0, 'first', 1], [$during, $requestsDuring, $after, $requests]);
    }

    // Forced order: another worker cools the team account's pin while the default account is tried, after the read that found both free. The team account is read again before it sends, so it gets no request and the call waits for the pin.
    public function testAFallbackAccountWhosePinCoolsDuringTheCallGetsNoRequest(): void
    {
        $requests = 0;
        $default = $this->answering(function (): never {
            $this->pins->coolDown($this->deepinfra('team'), 120);

            throw new RateLimitExceededException(120);
        });
        $team = $this->answering(static function () use (&$requests): string {
            ++$requests;

            return 'team';
        });

        $out = $this->failureOf(fn (): string => $this->accounts($default, $team)->invoke(self::PINNED, 'Review.')->asText());

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame([120, 0], [$out->secondsUntilAnAccountIsFree, $requests]);
    }

    // Forced order: the default account's pin cools right after the scan read the team account, so after the read that found the default account free. The default account is read again before it sends too, so it gets no request and the team account answers.
    public function testTheFirstFreeAccountWhosePinCoolsDuringTheScanGetsNoRequest(): void
    {
        $requests = 0;
        $default = $this->answering(static function () use (&$requests): string {
            ++$requests;

            return 'default';
        });
        $coolingDuringTheScan = new PinCoolingAfterARead($this->pins, $this->deepinfra('team'), $this->deepinfra(Account::DEFAULT), 120);
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default, 'team' => $this->answering('team')], new NoCooldowns(), $this->clock, new NullLogger(), $coolingDuringTheScan);

        $answer = $platform->invoke(self::PINNED, 'Review.')->asText();

        self::assertSame(['team', 0], [$answer, $requests]);
    }

    // Forced order: the default account's 429 cools the team account's pin for a second, so the team account is skipped before it sends, and that pin ends while the last account is tried. The team account is free once the call failed but was never sent to, so the call waits a second instead of failing, and the next call goes to the team account.
    public function testAnAccountThatFreesUpDuringTheCallGivesAWaitOfASecondAndTheNextCallReachesIt(): void
    {
        $accounts = new AccountWindowsOnAClock($this->clock, [Provider::OpenAiCompatible->value => [Account::DEFAULT, 'team', 'last']]);
        $default = $this->answering(function (): never {
            $this->pins->coolDown($this->deepinfra('team'), 1);

            throw new RateLimitExceededException(120);
        });
        $last = $this->answering(function (): never {
            $this->clock->sleep(2);

            throw new RateLimitExceededException(120);
        });
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default, 'team' => $this->answering('team'), 'last' => $last], $accounts, $this->clock, new NullLogger(), $this->pins);

        $out = $this->failureOf(static fn (): string => $platform->invoke(self::PINNED, 'Review.')->asText());

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame(1, $out->secondsUntilAnAccountIsFree);
        self::assertSame('team', $platform->invoke(self::PINNED, 'Review.')->asText());
    }

    public function testThePinStoreAloneLeavesAnotherRouterProviderOnTheSameAccountFree(): void
    {
        $this->pins->coolDown($this->deepinfra(Account::DEFAULT), 300);

        $answer = $this->accounts($this->answering('first'), $this->answering('second'))->invoke('moonshotai/Kimi-K3.1-Pro:novita', 'Review.');

        self::assertSame('first', $answer->asText());
    }

    public function testWhenEveryPinCoolsTheCallSendsNothingAndWaitsForTheShortestRemainingPin(): void
    {
        $this->pins->coolDown($this->deepinfra(Account::DEFAULT), 120);
        $this->pins->coolDown($this->deepinfra('team'), 30);
        $default = new FailingWhenRead(new RateLimitExceededException(1));
        $team = new FailingWhenRead(new RateLimitExceededException(1));

        $out = $this->failureOf(fn (): object => $this->accounts($default, $team)->invoke(self::PINNED, 'Review.'));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame([30, 0, 0], [$out->secondsUntilAnAccountIsFree, $default->calls, $team->calls]);
    }

    // Forced order: the default account's pin ends right after it was read, while the team account's pin is read. A second read of the stores would find the default account free and report a wait of 0 while the call was refused; the one read reports the wait it decided on.
    public function testAPinThatEndsBetweenTheReadsOfTwoAccountsStillGivesAWaitAboveZero(): void
    {
        $this->pins->coolDown($this->deepinfra(Account::DEFAULT), 1);
        $this->pins->coolDown($this->deepinfra('team'), 120);
        $endingWhileRead = new ClockMovingAfterARead($this->pins, $this->clock, $this->deepinfra(Account::DEFAULT), 2);
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $this->answering('first'), 'team' => $this->answering('second')], new NoCooldowns(), $this->clock, new NullLogger(), $endingWhileRead);

        $out = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame(1, $out->secondsUntilAnAccountIsFree);
        self::assertFalse($this->pins->isCoolingDown($this->deepinfra(Account::DEFAULT)));
    }

    // Forced order: the account store refuses to save its window, so it reads every account free while the pin cools; the wait still comes from the pin.
    public function testAPinThatCoolsWhileTheAccountWindowWasNotSavedStillGivesAWaitAboveZero(): void
    {
        $pool = new class extends ArrayAdapter {
            #[Override]
            public function save(CacheItemInterface $item): bool
            {
                return str_starts_with($item->getKey(), 'pinned-provider-cooldown-') && parent::save($item);
            }
        };
        $clock = new NativeClock();
        $locks = new LockFactory(new InMemoryStore());
        $provider = Provider::OpenAiCompatible;
        $accounts = new AccountCooldownWindows(new ReadingsInMemory($clock), $pool, $locks, $clock, new InMemoryLedger(), new NullLogger(), StoredSerializerFactory::create(), [$provider->value => [Account::DEFAULT]]);
        $pins = new PinnedProviderCooldownWindows($pool, $clock, $locks);
        $platform = new AccountFailoverPlatform($provider, [Account::DEFAULT => $this->answering(static fn (): never => throw new RateLimitExceededException(120))], $accounts, $clock, new NullLogger(), $pins);

        $out = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame(0, $accounts->secondsUntilAnAccountIsFree($provider));
        self::assertGreaterThan(0, $out->secondsUntilAnAccountIsFree);
        self::assertLessThanOrEqual(120, $out->secondsUntilAnAccountIsFree);
    }

    /**
     * @param non-empty-string       $model
     * @param non-empty-list<string> $tails
     */
    #[DataProvider('modelsThatPinNothing')]
    public function testAModelIdOutsideTheRouterShapeCoolsNoPin(string $model, array $tails): void
    {
        $answer = $this->accounts(new FailingWhenRead(new RateLimitExceededException(120)), $this->answering('second'))->invoke($model, 'Review.');

        $this->failureOf(static fn (): string => $answer->asText());

        self::assertSame(array_fill(0, count($tails), 0), array_map(fn (string $tail): int => $this->pins->secondsUntilFree($this->pinned(Account::DEFAULT, $tail)), $tails));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-list<string>}>
     */
    public static function modelsThatPinNothing(): iterable
    {
        yield 'a trailing newline' => ["owner/model:router\n", ['router', "router\n"]];
        yield 'a newline before the colon' => ["owner/model\n:router", ['router']];
        yield 'a nested owner' => ['owner/group/model:router', ['router']];
        yield 'a no-break space in the model' => ["owner/mo\u{00A0}del:router", ['router']];
        yield 'a next-line character in the model' => ["owner/mo\u{0085}del:router", ['router']];
        yield 'a tag without an owner' => ['llama3:8b', ['8b']];
        yield 'a fine-tune id' => ['ft:gpt-4o-mini:org:custom:id', ['id']];
    }

    // Forced order: the pin store's cache fails while saving, so the pin is not saved; the account cools for the 429's wait instead, so the provider is not called again at once, and the call reports that wait instead of the store's error.
    public function testAPinStoreThatFailsWhileSavingCoolsTheAccountInsteadAndKeepsThe429(): void
    {
        $pool = new class extends ArrayAdapter {
            #[Override]
            public function save(CacheItemInterface $item): bool
            {
                if (str_starts_with($item->getKey(), 'pinned-provider-cooldown-')) {
                    throw new RuntimeException('The disk is full.');
                }

                return parent::save($item);
            }
        };
        $accounts = new AccountWindowsOnAClock($this->clock, [Provider::OpenAiCompatible->value => [Account::DEFAULT]]);
        $pins = new PinnedProviderCooldownWindows($pool, $this->clock, new LockFactory(new InMemoryStore()));
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $this->answering(static fn (): never => throw new RateLimitExceededException(120))], $accounts, $this->clock, new NullLogger(), $pins);

        $out = $this->failureOf(static fn (): object => $platform->invoke(self::PINNED, 'Review.'));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame([120, 120, 0], [$out->secondsUntilAnAccountIsFree, $accounts->secondsUntilFree(new Account(Provider::OpenAiCompatible, Account::DEFAULT)), $pins->secondsUntilFree($this->deepinfra(Account::DEFAULT))]);
    }

    // Forced order: the account store throws on any write; a pinned 429 never writes it (issue #2861), so the pin cools for the 429's wait and the caller gets that wait.
    public function testAPinned429LeavesTheAccountStoreAloneAndTheCallerGetsThePinsWait(): void
    {
        $accounts = self::createStub(AccountCooldowns::class);
        $accounts->method('coolDown')->willThrowException(new RuntimeException('The disk is full.'));
        $limited = new RateLimitExceededException(120);
        $logger = new BufferingLogger();
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => new FailingWhenRead($limited)], $accounts, $this->clock, $logger, $this->pins);
        $answer = $platform->invoke(self::PINNED, 'Review.');

        $out = $this->failureOf(static fn (): string => $answer->asText());

        self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
        self::assertSame([120, 120], [$out->secondsUntilAnAccountIsFree, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT))]);
        self::assertNotContains('The cooldown of account {account} could not be saved.', array_column($logger->cleanLogs(), 1));
    }

    // Forced order: the account store's cache refuses the window's save without throwing, so the account stays free; the refusal is logged instead of recorded as a cooldown and the caller gets the provider's own 429. A pinned 429 never writes the account window (issue #2861), so a model that pins nothing drives the write.
    public function testAnAccountWindowTheCacheRefusesIsLoggedAndRecordsNoCooldown(): void
    {
        $pool = new class extends ArrayAdapter {
            #[Override]
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };
        $clock = new NativeClock();
        $provider = Provider::OpenAiCompatible;
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();
        $accounts = new AccountCooldownWindows(new ReadingsInMemory($clock), $pool, new LockFactory(new InMemoryStore()), $clock, $ledger, $logger, StoredSerializerFactory::create(), [$provider->value => [Account::DEFAULT]]);
        $limited = new RateLimitExceededException(120);
        $platform = new AccountFailoverPlatform($provider, [Account::DEFAULT => new FailingWhenRead($limited)], $accounts, $this->clock, $logger, $this->pins);
        $answer = $platform->invoke(self::UNPINNED, 'Review.');

        $out = $this->failureOf(static fn (): string => $answer->asText());

        self::assertSame($limited, $out);
        self::assertSame(0, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT)));
        self::assertSame(0, $accounts->secondsUntilFree(new Account($provider, Account::DEFAULT)));
        self::assertNotContains(LedgerEventKind::AccountCooledDown, $ledger->kinds());
        self::assertContains('The cooldown of account {account} could not be saved.', array_column($logger->cleanLogs(), 1));
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19T12:00:00.400000+00:00');
        $this->pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
    }

    private function answering(string|Closure $answer): PlatformInterface
    {
        return MockPlatformFactory::createPlatform($answer);
    }

    private function accounts(PlatformInterface $default, PlatformInterface $team): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default, 'team' => $team], new NoCooldowns(), $this->clock, new NullLogger(), $this->pins);
    }

    private function deepinfra(string $account): PinnedProvider
    {
        return $this->pinned($account, 'deepinfra');
    }

    private function pinned(string $account, string $routerProvider): PinnedProvider
    {
        return new PinnedProvider(new Account(Provider::OpenAiCompatible, $account), $routerProvider);
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
