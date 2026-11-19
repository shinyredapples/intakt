<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\PinnedProviderCooldownNotSaved;
use Intakt\Core\Ai\Contract\PinnedProviderCooldowns;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Core\Ai\Infrastructure\PinnedProviderSkipped;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// The pinned windows run on a mocked clock so a test moves past their end; the account windows stay on the native clock, because RateLimiter reads the system time itself.
#[CoversClass(AccountFailoverPlatform::class)]
#[CoversClass(AccountPlatform::class)]
#[UsesClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(PinnedProviderSkipped::class)]
#[UsesClass(PinnedProviderCooldownNotSaved::class)]
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

    private AccountCooldownWindows $cooldowns;

    private PinnedProviderCooldownWindows $pinned;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19T12:00:00+00:00');
        $this->cooldowns = $this->accountWindows(Account::DEFAULT, 'team');
        $this->pinned = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
    }

    public function testA429FromAPinnedProviderCoolsItDownForItsRetryAfterAndTheCallGoesToTheNextAccount(): void
    {
        $result = $this->accounts($this->limited(120), $this->answering('second'))->invoke(self::PINNED, 'Review.');

        self::assertSame('second', $result->asText());
        self::assertTrue($this->pinned->isCoolingDown($this->deepinfra(Account::DEFAULT)));
        $this->clock->sleep(119);
        self::assertTrue($this->pinned->isCoolingDown($this->deepinfra(Account::DEFAULT)));
        $this->clock->sleep(1);
        self::assertFalse($this->pinned->isCoolingDown($this->deepinfra(Account::DEFAULT)));
    }

    public function testA429WithoutRetryAfterCoolsThePinnedProviderDownForTheDefaultMinute(): void
    {
        $this->accounts($this->limited(null), $this->answering('second'))->invoke(self::PINNED, 'Review.')->asText();

        $this->clock->sleep(AccountPlatform::PINNED_PROVIDER_COOLDOWN_SECONDS - 1);
        self::assertTrue($this->pinned->isCoolingDown($this->deepinfra(Account::DEFAULT)));
        $this->clock->sleep(1);
        self::assertFalse($this->pinned->isCoolingDown($this->deepinfra(Account::DEFAULT)));
    }

    public function testA429ReadFromTheAnswerCoolsThePinnedProviderDownToo(): void
    {
        $answer = $this->accounts(new FailingWhenRead(new RateLimitExceededException(90)), $this->answering('second'))->invoke(self::PINNED, 'Review.');

        try {
            $answer->asText();
            self::fail('The answer was expected to fail when read.');
        } catch (RateLimitExceededException) {
        }

        self::assertTrue($this->pinned->isCoolingDown($this->deepinfra(Account::DEFAULT)));
    }

    public function testAPinnedProviderThatCoolsDownIsSkippedWithoutACall(): void
    {
        $this->pinned->coolDown($this->deepinfra(Account::DEFAULT), 300);
        $skipped = $this->createMock(PlatformInterface::class);
        $skipped->expects(self::never())->method('invoke');

        $result = $this->accounts($skipped, $this->answering('second'))->invoke(self::PINNED, 'Review.');

        self::assertSame('second', $result->asText());
    }

    // The accounts never cool down here, so the pinned window alone decides and the mocked clock can move past it.
    public function testAScripted429CoolsThePinTheNextCallSendsItNothingAndTheFirstCallAfterItsCooldownReachesItAgain(): void
    {
        $requests = 0;
        $default = MockPlatformFactory::createPlatform(static function () use (&$requests): string {
            if (1 === ++$requests) {
                throw new RateLimitExceededException(120);
            }

            return 'first';
        });
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default, 'team' => $this->answering('second')], new NoCooldowns(), new NativeClock(), new NullLogger(), $this->pinned);

        $limited = $platform->invoke(self::PINNED, 'Review.')->asText();
        $during = $platform->invoke(self::PINNED, 'Review.')->asText();
        $requestsDuring = $requests;
        $this->clock->sleep(120);
        $after = $platform->invoke(self::PINNED, 'Review.')->asText();

        self::assertSame([['second', 'second', 'first'], 1, 2], [[$limited, $during, $after], $requestsDuring, $requests]);
    }

    public function testAFallbackWhosePinAnotherWorkerCoolsDownDuringTheCallIsSentNothing(): void
    {
        $first = MockPlatformFactory::createPlatform(function (): never {
            $this->pinned->coolDown($this->deepinfra('team'), 60);

            throw new RuntimeException('The first account failed.');
        });
        $fallback = $this->createMock(PlatformInterface::class);
        $fallback->expects(self::never())->method('invoke');
        $platform = new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $first, 'team' => $fallback], new NoCooldowns(), new NativeClock(), new NullLogger(), $this->pinned);

        $this->expectException(RuntimeException::class);

        $platform->invoke(self::PINNED, 'Review.');
    }

    // A second store stands for the account windows once they ended, because they run on the native clock, which a test cannot move.
    public function testAPinnedWindowThatTwoAnswersInFlightMadeOutliveTheAccountWindowKeepsTheCallerWaiting(): void
    {
        $short = $this->onlyDefault(new FailingWhenRead(new RateLimitExceededException(60)), $this->cooldowns)->invoke(self::PINNED, 'Review.');
        $long = $this->onlyDefault(new FailingWhenRead(new RateLimitExceededException(600)), $this->cooldowns)->invoke(self::PINNED, 'Review.');
        $this->read($short);
        $this->read($long);
        $this->clock->sleep(60);
        $ended = $this->accountWindows(Account::DEFAULT);

        $wait = $this->waitAfterEveryAccountIsOut($this->onlyDefault($this->answering('first'), $ended));

        self::assertSame([true, true], [in_array($wait, [539, 540], true), 0 < $ended->secondsUntilAnAccountIsFree(Provider::OpenAiCompatible)]);
    }

    public function testAPinnedWindowLongerThanTheAccountsLongestWaitKeepsTheCallerWaitingAfterTheAccountWindow(): void
    {
        $this->read($this->onlyDefault(new FailingWhenRead(new RateLimitExceededException(90000)), $this->cooldowns)->invoke(self::PINNED, 'Review.'));
        $this->clock->sleep(86400);

        $wait = $this->waitAfterEveryAccountIsOut($this->onlyDefault($this->answering('first'), $this->accountWindows(Account::DEFAULT)));

        self::assertContains($wait, [3599, 3600]);
    }

    public function testAnAccountWindowThatEndsBetweenTheRefreshAndTheReadIsStartedAgainWhileThePinCools(): void
    {
        $this->pinned->coolDown($this->deepinfra(Account::DEFAULT), 120);
        $ending = new WindowEndingBeforeTheRead();

        $wait = $this->waitAfterEveryAccountIsOut(new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $this->answering('first')], $ending, new NativeClock(), new NullLogger(), $this->pinned));

        self::assertSame([120, true], [$wait, $ending->isCoolingDown(new Account(Provider::OpenAiCompatible, Account::DEFAULT))]);
    }

    public function testAnAccountWindowThatEndsBeforeTheReadStaysEndedWhenNoPinCools(): void
    {
        $ended = $this->createMock(AccountCooldowns::class);
        $ended->method('isCoolingDown')->willReturn(true);
        $ended->method('secondsUntilAnAccountIsFree')->willReturn(0);
        $ended->expects(self::never())->method('coolDown');

        $wait = $this->waitAfterEveryAccountIsOut(new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $this->answering('first')], $ended, new NativeClock(), new NullLogger(), $this->pinned));

        self::assertSame(0, $wait);
    }

    public function testAnAccountWhosePinCoolsIsNotCooledDownWhileAnotherAccountTakesTheCall(): void
    {
        $this->pinned->coolDown($this->deepinfra(Account::DEFAULT), 300);

        $this->accounts($this->answering('first'), $this->answering('second'))->invoke(self::PINNED, 'Review.')->asText();

        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::OpenAiCompatible, Account::DEFAULT)));
    }

    public function testAPinnedProviderThatCoolsDownLeavesTheAccountFreeForAnotherPin(): void
    {
        $this->pinned->coolDown($this->deepinfra(Account::DEFAULT), 300);

        $result = $this->accounts($this->answering('first'), $this->answering('second'))->invoke('moonshotai/Kimi-K3.1-Pro:together', 'Review.');

        self::assertSame('first', $result->asText());
    }

    public function testAPinnedProviderNamedByAModelObjectWithOptionsIsSkippedToo(): void
    {
        $this->pinned->coolDown($this->deepinfra(Account::DEFAULT), 300);
        $skipped = $this->createMock(PlatformInterface::class);
        $skipped->expects(self::never())->method('invoke');

        $result = $this->accounts($skipped, $this->answering('second'))->invoke(new Model(self::PINNED.'?temperature=0'), 'Review.');

        self::assertSame('second', $result->asText());
    }

    public function testAPinned429StillCoolsTheAccountDownSoTheCallerKeepsItsWait(): void
    {
        $this->accounts($this->limited(120), $this->answering('second'))->invoke(self::PINNED, 'Review.')->asText();

        self::assertTrue($this->cooldowns->isCoolingDown(new Account(Provider::OpenAiCompatible, Account::DEFAULT)));
    }

    public function testA429ForAModelWithoutAPinCoolsTheAccountAndNoPinnedProvider(): void
    {
        $pinned = $this->createMock(PinnedProviderCooldowns::class);
        $pinned->expects(self::never())->method('coolDown');

        $this->accounts($this->limited(120), $this->answering('second'), $pinned)->invoke('moonshotai/Kimi-K3.1-Pro', 'Review.')->asText();

        self::assertTrue($this->cooldowns->isCoolingDown(new Account(Provider::OpenAiCompatible, Account::DEFAULT)));
    }

    public function testACallWhoseEveryPinnedProviderCoolsDownSendsNothingAndSaysEveryAccountIsOut(): void
    {
        $this->pinned->coolDown($this->deepinfra(Account::DEFAULT), 300);
        $this->pinned->coolDown($this->deepinfra('team'), 300);
        $skipped = $this->createMock(PlatformInterface::class);
        $skipped->expects(self::never())->method('invoke');

        $this->expectException(EveryAccountCoolingDown::class);

        $this->accounts($skipped, $skipped)->invoke(self::PINNED, 'Review.');
    }

    public function testAPinnedCooldownTheStoreCannotSaveIsLoggedAndTheProviders429StillReachesTheCaller(): void
    {
        $refusing = self::createStub(PinnedProviderCooldowns::class);
        $refusing->method('coolDown')->willThrowException(new PinnedProviderCooldownNotSaved($this->deepinfra(Account::DEFAULT)));
        $logger = new BufferingLogger();
        $account = new AccountPlatform(new Account(Provider::OpenAiCompatible, Account::DEFAULT), $this->limited(120), $this->cooldowns, new NativeClock(), $logger, new SendFailures(), $refusing, $this->deepinfra(Account::DEFAULT));

        try {
            $account->invoke(self::PINNED, 'Review.');
            self::fail('The 429 was expected to reach the caller.');
        } catch (RateLimitExceededException) {
        }

        self::assertContains('The cooldown of pinned provider {pinned} could not be saved.', array_column($logger->cleanLogs(), 1));
    }

    private function accounts(PlatformInterface $default, PlatformInterface $team, ?PinnedProviderCooldowns $pinned = null): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default, 'team' => $team], $this->cooldowns, new NativeClock(), new NullLogger(), $pinned ?? $this->pinned);
    }

    private function onlyDefault(PlatformInterface $default, AccountCooldownWindows $cooldowns): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::OpenAiCompatible, [Account::DEFAULT => $default], $cooldowns, new NativeClock(), new NullLogger(), $this->pinned);
    }

    private function accountWindows(string ...$accounts): AccountCooldownWindows
    {
        return new AccountCooldownWindows(new ReadingsInMemory(new NativeClock()), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::OpenAiCompatible->value => array_values($accounts)]);
    }

    private function read(DeferredResult $answer): void
    {
        try {
            $answer->asText();
            self::fail('The answer was expected to fail when read.');
        } catch (RateLimitExceededException) {
        }
    }

    private function waitAfterEveryAccountIsOut(AccountFailoverPlatform $platform): int
    {
        try {
            $platform->invoke(self::PINNED, 'Review.');
        } catch (EveryAccountCoolingDown $out) {
            return $out->secondsUntilAnAccountIsFree;
        }

        self::fail('Every account was expected to be out.');
    }

    private function limited(?int $retryAfter): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static fn (): never => throw new RateLimitExceededException($retryAfter));
    }

    private function answering(string $answer): PlatformInterface
    {
        return MockPlatformFactory::createPlatform($answer);
    }

    private function deepinfra(string $account): PinnedProvider
    {
        return new PinnedProvider(new Account(Provider::OpenAiCompatible, $account), 'deepinfra');
    }
}
