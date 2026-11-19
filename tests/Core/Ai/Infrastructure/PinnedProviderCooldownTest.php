<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
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
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// The accounts never cool down here unless a test says so, so the pinned windows on the mocked clock alone decide.
#[CoversClass(AccountFailoverPlatform::class)]
#[CoversClass(AccountPlatform::class)]
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

    private MockClock $clock;

    private PinnedProviderCooldownWindows $pins;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19T12:00:00.400000+00:00');
        $this->pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
    }

    public function testA429FromAPinnedProviderCoolsItsPinForTheRetryAfterAndTheCallGoesToTheNextAccount(): void
    {
        $answer = $this->accounts($this->answering(static fn (): never => throw new RateLimitExceededException(120)), $this->answering('second'))->invoke(self::PINNED, 'Review.');

        self::assertSame('second', $answer->asText());
        self::assertSame(120, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT)));
        self::assertSame(0, $this->pins->secondsUntilFree($this->deepinfra('team')));
    }

    public function testA429WithoutARetryAfterCoolsThePinForTheDefaultMinute(): void
    {
        $this->accounts($this->answering(static fn (): never => throw new RateLimitExceededException()), $this->answering('second'))->invoke(self::PINNED, 'Review.')->asText();

        self::assertSame(60, $this->pins->secondsUntilFree($this->deepinfra(Account::DEFAULT)));
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

    public function testAPinOnOneModelLeavesAnotherRouterProviderOnTheSameAccountFree(): void
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
     * @param non-empty-string $model
     */
    #[DataProvider('modelsThatPinNothing')]
    public function testAModelIdOutsideTheRouterShapeCoolsNoPin(string $model, string $tail): void
    {
        $answer = $this->accounts(new FailingWhenRead(new RateLimitExceededException(120)), $this->answering('second'))->invoke($model, 'Review.');

        $this->failureOf(static fn (): string => $answer->asText());

        self::assertSame(0, $this->pins->secondsUntilFree($this->pinned(Account::DEFAULT, $tail)));
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function modelsThatPinNothing(): iterable
    {
        yield 'a trailing newline' => ["owner/model:router\n", "router\n"];
        yield 'a tag without an owner' => ['llama3:8b', '8b'];
        yield 'a fine-tune id' => ['ft:gpt-4o-mini:org:custom:id', 'id'];
    }

    private function accounts(PlatformInterface $default, PlatformInterface $team): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default, 'team' => $team], new NoCooldowns(), $this->clock, new NullLogger(), $this->pins);
    }

    private function answering(string|Closure $answer): PlatformInterface
    {
        return MockPlatformFactory::createPlatform($answer);
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
