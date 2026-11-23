<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\CodexAccountFailure;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Budget\Contract\MeasuredUse;
use Intakt\Core\Budget\Contract\Usage;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowReading;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// On the native clock, because the RateLimiter behind the cooldowns reads the system time itself.
#[CoversClass(AccountPlatform::class)]
#[CoversClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(CodexAccountFailure::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(Usage::class)]
#[UsesClass(UsageWindow::class)]
#[UsesClass(WindowReading::class)]
final class AccountPlatformCooldownTest extends TestCase
{
    private const int RESET_IN_SECONDS = 3000;

    public function testAnExhaustedCodexPlanCoolsDownUntilItsSpentWindowResets(): void
    {
        $cooldowns = $this->cooldowns($this->spentReadings());

        $failure = $this->failedCall($cooldowns, CodexAccountFailure::UsageLimitReached->exception());

        self::assertInstanceOf(RateLimitExceededException::class, $failure);
        self::assertEqualsWithDelta(self::RESET_IN_SECONDS, $cooldowns->secondsUntilAnAccountIsFree(Provider::Codex), 1);
    }

    public function testATemporaryOutageOfASpentAccountKeepsTheOneMinuteCooldown(): void
    {
        $cooldowns = $this->cooldowns($this->spentReadings());

        $failure = $this->failedCall($cooldowns, new ServerException(503));

        self::assertInstanceOf(ServerException::class, $failure);
        self::assertEqualsWithDelta(60, $cooldowns->secondsUntilAnAccountIsFree(Provider::Codex), 1);
    }

    public function testALimitWithoutAResetOnAnApiAccountWithASpentReadingKeepsTheOneMinuteCooldown(): void
    {
        $account = new Account(Provider::Anthropic, Account::DEFAULT);
        $clock = new NativeClock();
        $readings = new ReadingsInMemory($clock);
        $readings->record(new WindowReading($account, UsageWindow::FiveHour, new Usage(100.0, $clock->now()->modify(sprintf('+%d seconds', self::RESET_IN_SECONDS)))));
        $cooldowns = new AccountCooldownWindows($readings, new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, new InMemoryLedger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [Account::DEFAULT]]);

        $failure = $this->failedCall($cooldowns, new RateLimitExceededException(), $account);

        self::assertInstanceOf(RateLimitExceededException::class, $failure);
        self::assertEqualsWithDelta(60, $cooldowns->secondsUntilAnAccountIsFree(Provider::Anthropic), 1);
    }

    public function testAnExhaustedPlanWhoseReadingsCannotBeReadKeepsItsLimitAndTheOneMinuteCooldown(): void
    {
        $readings = self::createStub(MeasuredUse::class);
        $readings->method('of')->willThrowException(new RuntimeException('The readings are unavailable.'));
        $logger = new BufferingLogger();
        $cooldowns = $this->cooldowns($readings, $logger);

        $failure = $this->failedCall($cooldowns, CodexAccountFailure::UsageLimitReached->exception());

        self::assertInstanceOf(RateLimitExceededException::class, $failure);
        self::assertEqualsWithDelta(60, $cooldowns->secondsUntilAnAccountIsFree(Provider::Codex), 1);
        self::assertContains('The measured readings of account {account} could not be read.', array_column($logger->cleanLogs(), 1));
    }

    // Forced order (decision 77 of #2860): the cache throws on its first read after the window was kept, which a cooldown no longer makes, so the caller still gets the provider's own retryable error.
    public function testACacheReadThatFailsRightAfterTheCooldownWasSavedLeavesTheCallerTheProvidersError(): void
    {
        $clock = new NativeClock();
        $ledger = new InMemoryLedger();
        $cooldowns = new AccountCooldownWindows(new ReadingsInMemory($clock), new FailingAReadAfterASave(), new LockFactory(new InMemoryStore()), $clock, $ledger, new NullLogger(), StoredSerializerFactory::create(), [Provider::Codex->value => [Account::DEFAULT]]);
        $failure = new ServerException(503);
        $platform = new AccountPlatform($this->account(), new FailingWhenRead($failure), $cooldowns, $clock, new NullLogger(), new SendFailures(), new NoPinnedProviderCooldowns(), null);
        $answer = $platform->invoke('model', 'review');

        try {
            $answer->asText();
            self::fail('The answer was expected to fail.');
        } catch (ServerException $out) {
            self::assertSame($failure, $out);
        }

        self::assertSame([LedgerEventKind::AccountCooledDown], $ledger->kinds());
    }

    private function spentReadings(): ReadingsInMemory
    {
        $clock = new NativeClock();
        $readings = new ReadingsInMemory($clock);
        $readings->record(new WindowReading($this->account(), UsageWindow::FiveHour, new Usage(100.0, $clock->now()->modify(sprintf('+%d seconds', self::RESET_IN_SECONDS)))));

        return $readings;
    }

    private function cooldowns(MeasuredUse $readings, ?BufferingLogger $logger = null): AccountCooldownWindows
    {
        return new AccountCooldownWindows($readings, new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(), $logger ?? new NullLogger(), StoredSerializerFactory::create(), [Provider::Codex->value => [Account::DEFAULT]]);
    }

    private function failedCall(AccountCooldownWindows $cooldowns, Throwable $thrown, ?Account $account = null): Throwable
    {
        $platform = self::createStub(PlatformInterface::class);
        $platform->method('invoke')->willThrowException($thrown);
        $wrapped = new AccountPlatform($account ?? $this->account(), $platform, $cooldowns, new NativeClock(), new NullLogger(), new SendFailures(), new NoPinnedProviderCooldowns(), null);

        try {
            $wrapped->invoke('model', 'review');
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The call was expected to fail.');
    }

    private function account(): Account
    {
        return new Account(Provider::Codex, Account::DEFAULT);
    }
}
