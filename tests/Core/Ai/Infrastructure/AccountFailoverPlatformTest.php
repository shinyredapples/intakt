<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// On the native clock, because the cooldowns are RateLimiter windows, which read the system time themselves.
#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
final class AccountFailoverPlatformTest extends TestCase
{
    private AccountCooldownWindows $cooldowns;

    protected function setUp(): void
    {
        $this->cooldowns = new AccountCooldownWindows(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(), new NullLogger(), [Provider::Anthropic->value => [Account::DEFAULT, 'team']]);
    }

    public function testACallGoesToTheFirstAccountInTheOwnersOrder(): void
    {
        $result = $this->accounts($this->answering('first'), $this->answering('second'))->invoke('big', 'Review.');

        self::assertSame('first', $result->asText());
        self::assertSame('anthropic.default', $result->getMetadata()->get(Account::METADATA));
    }

    public function testACallSkipsAnAccountThatCoolsDownAndGoesToTheNextOfTheSameProvider(): void
    {
        $logger = new BufferingLogger();
        $this->cooldowns->coolDown($this->account(Account::DEFAULT), 300);

        $result = $this->accounts($this->answering('first'), $this->answering('second'), $logger)->invoke('big', 'Review.');

        self::assertSame('second', $result->asText());
        self::assertSame('anthropic.team', $result->getMetadata()->get(Account::METADATA));
        /** @var list<array{0: string, 1: string, 2: array<string, string>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('info', $logs[0][0]);
        self::assertSame(['provider' => 'anthropic', 'first' => 'default', 'next' => 'team'], $logs[0][2]);
    }

    public function testAFailedAccountIsLoggedWithItsKeyAndTheFailureClassButNeverTheProvidersMessage(): void
    {
        $logger = new BufferingLogger();

        $this->accounts($this->answering(static fn (): never => throw new RateLimitExceededException(300, 'invalid key SENTINEL')), $this->answering('second'), $logger)->invoke('big', 'Review.')->asText();

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertStringNotContainsString('SENTINEL', print_r($logs, true));
        self::assertContains(['warning', 'Account {account} failed with {failure}.', ['account' => 'anthropic.default', 'failure' => RateLimitExceededException::class]], $logs);
    }

    public function testACallWhileEveryAccountCoolsDownIsRefusedBeforeAnyAccountIsCalled(): void
    {
        $first = new FailingWhenRead(new RuntimeException('never read'));
        $second = new FailingWhenRead(new RuntimeException('never read'));
        $this->cooldowns->coolDown($this->account(Account::DEFAULT), 300);
        $this->cooldowns->coolDown($this->account('team'), 120);

        try {
            $_ = $this->accounts($first, $second)->invoke('big', 'Review.');
            self::fail('A provider whose every account is out was called.');
        } catch (EveryAccountCoolingDown $out) {
            self::assertEqualsWithDelta(120, $out->secondsUntilAnAccountIsFree, 1);
        }

        self::assertSame(0, $first->calls + $second->calls);
    }

    public function testALimitReadFromTheAnswerCoolsDownTheAccountThatServedItUntilTheVendorsReset(): void
    {
        $accounts = $this->accounts(new FailingWhenRead(new RateLimitExceededException(300)), $this->answering('second'));

        $this->read($accounts->invoke('big', 'Review.'));

        self::assertTrue($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
        self::assertSame('second', $accounts->invoke('big', 'Review.')->asText());
    }

    public function testATransientFailureReadFromTheAnswerCoolsTheAccountDown(): void
    {
        $this->read($this->accounts(new FailingWhenRead(new ServerException(503)), $this->answering('second'))->invoke('big', 'Review.'));

        self::assertTrue($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testAnAnswerThatFailsForAnotherReasonLeavesTheAccountFree(): void
    {
        $this->read($this->accounts(new FailingWhenRead(new RuntimeException('not a verdict')), $this->answering('second'))->invoke('big', 'Review.'));

        self::assertFalse($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testAnAccountThatIsLimitedAsTheCallIsSentHandsTheCallToTheNextAccount(): void
    {
        $result = $this->accounts($this->answering(static fn (): never => throw new RateLimitExceededException(300)), $this->answering('second'))->invoke('big', 'Review.');

        self::assertSame('second', $result->asText());
        self::assertTrue($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testACallWhoseEveryAccountIsLimitedAsItIsSentSaysEveryAccountCoolsDown(): void
    {
        $limited = static fn (): never => throw new RateLimitExceededException(300);

        $this->expectException(EveryAccountCoolingDown::class);

        $this->accounts($this->answering($limited), $this->answering($limited))->invoke('big', 'Review.');
    }

    public function testACallWhoseEveryAccountFailsWithoutALimitKeepsTheFailoverError(): void
    {
        $broken = static fn (): never => throw new RuntimeException('bad request');

        $this->expectException(PlatformRuntimeException::class);

        try {
            $_ = $this->accounts($this->answering($broken), $this->answering($broken))->invoke('big', 'Review.');
        } finally {
            self::assertFalse($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
        }
    }

    public function testTheModelCatalogIsTheFirstAccounts(): void
    {
        $first = $this->answering('first');

        self::assertSame($first->getModelCatalog(), $this->accounts($first, $this->answering('second'))->getModelCatalog());
    }

    private function read(DeferredResult $result): void
    {
        try {
            $result->getResult();
            self::fail('The answer was expected to fail when read.');
        } catch (Throwable) {
        }
    }

    private function answering(string|Closure $answer): PlatformInterface
    {
        return MockPlatformFactory::createPlatform($answer);
    }

    private function accounts(PlatformInterface $default, PlatformInterface $team, ?BufferingLogger $logger = null): AccountFailoverPlatform
    {
        return new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $default, 'team' => $team], $this->cooldowns, new NativeClock(), $logger ?? new NullLogger());
    }

    private function account(string $name): Account
    {
        return new Account(Provider::Anthropic, $name);
    }
}
