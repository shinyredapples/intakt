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
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// On the native clock, because the cooldowns are RateLimiter windows, which read the system time themselves.
#[CoversClass(AccountFailoverPlatform::class)]
#[CoversClass(AccountPlatform::class)]
#[CoversClass(SendFailures::class)]
#[CoversClass(TypedRejectionConverter::class)]
#[CoversClass(EveryAccountCoolingDown::class)]
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

    public function testARevokedKeyReadFromTheAnswerCoolsTheAccountDown(): void
    {
        $this->read($this->accounts(new FailingWhenRead(new AuthenticationException('revoked')), $this->answering('second'))->invoke('big', 'Review.'));

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

    public function testACallWhoseEveryAccountRejectsItsKeyAsItIsSentIsTheRejectionAndNotAWait(): void
    {
        $revoked = new AuthenticationException('revoked');

        try {
            $_ = $this->accounts($this->answering(static fn (): never => throw $revoked), $this->answering(static fn (): never => throw $revoked))->invoke('big', 'Review.');
            self::fail('Every account rejected its key, yet the call answered.');
        } catch (Throwable $failure) {
            self::assertSame($revoked, $failure);
        }

        self::assertTrue($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testACallWithOneAccountRejectingItsKeyAndTheOtherLimitedWaitsForTheLimitedOne(): void
    {
        $this->expectException(EveryAccountCoolingDown::class);

        $this->accounts($this->answering(static fn (): never => throw new AuthenticationException('revoked')), $this->answering(static fn (): never => throw new RateLimitExceededException(300)))->invoke('big', 'Review.');
    }

    public function testACallWhoseOnlyFreeAccountRejectsItsKeyWhileAnotherCoolsDownWaitsForTheCoolingOne(): void
    {
        $this->cooldowns->coolDown($this->account(Account::DEFAULT), 300);

        try {
            $_ = $this->accounts($this->answering('first'), $this->answering(static fn (): never => throw new AuthenticationException('revoked')))->invoke('big', 'Review.');
            self::fail('The only free account rejected its key, yet the call answered.');
        } catch (EveryAccountCoolingDown $out) {
            self::assertGreaterThan(0, $out->secondsUntilAnAccountIsFree);
        }
    }

    // The reviewer's probe: worker A, a moment earlier, saw only the default account free and its key was rejected, so B finds it cooling down from that rejection and the team account rejects too.
    public function testTwoRevokedAccountsWhoseRejectionsAreOffsetEndTheCallAsARejection(): void
    {
        $revoked = static fn (): never => throw new AuthenticationException('revoked');
        $onlyDefault = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $this->answering($revoked)], $this->cooldowns, new NativeClock(), new NullLogger());
        try {
            $_ = $onlyDefault->invoke('big', 'Review.');
        } catch (AuthenticationException) {
        }
        self::assertTrue($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));

        try {
            $_ = $this->accounts($this->answering($revoked), $this->answering($revoked))->invoke('big', 'Review.');
            self::fail('Every key of the provider is revoked, yet the call answered.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(AuthenticationException::class, $failure, 'Every key of the provider is revoked, yet the call waits for an account cooling down from a rejection.');
        }
    }

    public function testACallWhoseOnlyFreeAccountRejectsItsKeyBesideAnAccountCoolingDownFromItsOwnRejectionIsTheRejection(): void
    {
        $revoked = new AuthenticationException('revoked');
        $this->cooldowns->coolDownRejected($this->account(Account::DEFAULT));

        try {
            $_ = $this->accounts($this->answering('first'), $this->answering(static fn (): never => throw $revoked))->invoke('big', 'Review.');
            self::fail('Every key of the provider is revoked, yet the call answered.');
        } catch (Throwable $failure) {
            self::assertSame($revoked, $failure);
        }
    }

    public function testAnAnswerWhoseKeyIsRejectedWhenReadBesideAnAccountCoolingDownFromItsOwnRejectionIsTheRejection(): void
    {
        $revoked = new AuthenticationException('revoked');
        $this->cooldowns->coolDownRejected($this->account(Account::DEFAULT));
        $answer = $this->accounts($this->answering('first'), new FailingWhenRead($revoked))->invoke('big', 'Review.');

        try {
            $answer->asText();
            self::fail('Every key of the provider is revoked, yet the answer was read.');
        } catch (Throwable $failure) {
            self::assertSame($revoked, $failure);
        }
    }

    public function testAnAnswerWhoseKeyIsRejectedWhenReadWhileAnotherAccountCoolsDownWaitsForTheCoolingOne(): void
    {
        $this->cooldowns->coolDown($this->account(Account::DEFAULT), 300);
        $answer = $this->accounts($this->answering('first'), new FailingWhenRead(new AuthenticationException('revoked')))->invoke('big', 'Review.');

        try {
            $answer->asText();
            self::fail('The only free account rejected its key, yet the answer was read.');
        } catch (Throwable $out) {
            self::assertInstanceOf(EveryAccountCoolingDown::class, $out);
            self::assertGreaterThan(0, $out->secondsUntilAnAccountIsFree);
        }
    }

    public function testAnAnswerWhoseKeyIsRejectedWhenReadAfterTheOtherAccountRejectedItsKeyIsTheRejection(): void
    {
        $revoked = new AuthenticationException('revoked');
        $answer = $this->accounts($this->answering(static fn (): never => throw new AuthenticationException('revoked')), new FailingWhenRead($revoked))->invoke('big', 'Review.');

        try {
            $answer->asText();
            self::fail('Every account rejected its key, yet the answer was read.');
        } catch (Throwable $failure) {
            self::assertSame($revoked, $failure);
        }
    }

    public function testAnAnswerReadFromAnAccountThatAnswersKeepsItsTextAndItsAccount(): void
    {
        $this->cooldowns->coolDown($this->account(Account::DEFAULT), 300);

        $answer = $this->accounts($this->answering('first'), $this->answering('second'))->invoke('big', 'Review.');

        self::assertSame('anthropic.team', $answer->getMetadata()->get(Account::METADATA));
        self::assertSame('second', $answer->asText());
        self::assertSame('anthropic.team', $answer->getMetadata()->get(Account::METADATA));
    }

    public function testAnAnswerReadFromAnAccountCarriesItsTokenUsageOnce(): void
    {
        $usage = new TokenUsage(promptTokens: 10, totalTokens: 10);

        $answer = $this->accounts($this->extracting($usage, static fn (): TextResult => new TextResult('ok')), $this->answering('second'))->invoke('big', 'Review.');

        self::assertSame($usage, $answer->getResult()->getMetadata()->get('token_usage'));
        self::assertSame($usage, $answer->getMetadata()->get('token_usage'));
    }

    public function testAnAnswerThatFailsToMapStillHasItsTokenUsageReadByTheAccountsExtractor(): void
    {
        $usage = new TokenUsage(promptTokens: 9);
        $answer = $this->accounts($this->extracting($usage, static fn (): never => throw new RuntimeException('not a verdict')), $this->answering('second'))->invoke('big', 'Review.');

        $this->read($answer);

        self::assertSame($usage, $answer->getResultConverter()->getTokenUsageExtractor()?->extract($answer->getRawResult()));
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

    // Not MockPlatformFactory, because a provider's bridge reads the usage from the raw answer with its converter's extractor instead of carrying it on the converted result.
    private function extracting(TokenUsage $usage, Closure $convert): PlatformInterface
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willReturn($usage);
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('supports')->willReturn(true);
        $converter->method('convert')->willReturnCallback($convert);
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $platform = self::createStub(PlatformInterface::class);
        $platform->method('invoke')->willReturnCallback(static fn (): DeferredResult => new DeferredResult($converter, new InMemoryRawResult()));

        return $platform;
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
