<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Generator;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryEndpointUnreachable;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\KeyRejected;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountErrorReported;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\AccountStreamAnswered;
use Intakt\Core\Ai\Infrastructure\AccountStreamFailures;
use Intakt\Core\Ai\Infrastructure\CodexAccountFailure;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Tests\Core\Budget\ReadingsInMemory;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

// On the native clock, because the cooldowns are RateLimiter windows, which read the system time themselves.
#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(ModelWaits::class)]
#[CoversClass(AccountPlatform::class)]
#[CoversClass(AccountStreamFailures::class)]
#[CoversClass(AccountStreamAnswered::class)]
#[UsesClass(AccountErrorReported::class)]
#[UsesClass(CodexAccountFailure::class)]
#[CoversClass(SendFailures::class)]
#[CoversClass(TypedRejectionConverter::class)]
#[CoversClass(EveryAccountCoolingDown::class)]
#[CoversClass(EveryKeyRejected::class)]
#[CoversClass(EveryEndpointUnreachable::class)]
#[CoversClass(KeyRejected::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
final class AccountFailoverPlatformTest extends TestCase
{
    private AccountCooldownWindows $cooldowns;

    protected function setUp(): void
    {
        $this->cooldowns = new AccountCooldownWindows(new ReadingsInMemory(new NativeClock()), new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(), new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [Account::DEFAULT, 'team']]);
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

    public function testAFailedAccountIsLoggedWithItsKeyAndTheFailureForTheLogToRedact(): void
    {
        $logger = new BufferingLogger();
        $failure = new RateLimitExceededException(300, 'invalid key SENTINEL');

        $this->accounts($this->answering(static fn (): never => throw $failure), $this->answering('second'), $logger)->invoke('big', 'Review.')->asText();

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertContains(['warning', 'Account {account} failed.', ['account' => 'anthropic.default', 'exception' => $failure]], $logs);
    }

    public function testAnAnswerThatFailsToConvertForAnotherReasonIsNoFailedAccount(): void
    {
        $logger = new BufferingLogger();
        $answer = $this->accounts(new FailingWhenRead(new PlatformRuntimeException('Cannot deserialize the content.')), $this->answering('second'), $logger)->invoke('big', 'Review.');

        try {
            $answer->asText();
            self::fail('The answer could not be read, yet it was.');
        } catch (PlatformRuntimeException) {
        }

        self::assertSame([], $logger->cleanLogs());
        self::assertFalse($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testACallThatCannotBeSentForAnotherReasonIsLoggedWithoutCoolingTheAccountDown(): void
    {
        $logger = new BufferingLogger();
        $failure = new PlatformRuntimeException('The CLI crashed.');

        $this->accounts($this->answering(static fn (): never => throw $failure), $this->answering('second'), $logger)->invoke('big', 'Review.')->asText();

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertContains(['warning', 'Account {account} could not send the call.', ['account' => 'anthropic.default', 'exception' => $failure]], $logs);
        self::assertFalse($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testAnAccountErrorWhileSendingIsLoggedAsAFailedAccountAndNotAsACallThatCouldNotBeSent(): void
    {
        $logger = new BufferingLogger();
        $failure = new RateLimitExceededException(300);

        $this->accounts($this->answering(static fn (): never => throw $failure), $this->answering('second'), $logger)->invoke('big', 'Review.')->asText();

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame(['Account {account} failed.'], array_values(array_filter(array_column($logs, 1), static fn (string $message): bool => str_starts_with($message, 'Account {account} '))));
        self::assertTrue($this->cooldowns->isCoolingDown($this->account(Account::DEFAULT)));
    }

    public function testAnAccountErrorWrappedWhileSendingKeepsTheCouldNotSendWarning(): void
    {
        $logger = new BufferingLogger();
        $failure = new IncompleteStreamException('Cut off.', 0, new RateLimitExceededException(300));

        $this->accounts($this->answering(static fn (): never => throw $failure), $this->answering('second'), $logger)->invoke('big', 'Review.')->asText();

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame(['Account {account} failed.', 'Account {account} could not send the call.'], array_values(array_filter(array_column($logs, 1), static fn (string $message): bool => str_starts_with($message, 'Account {account} '))));
    }

    public function testARetryableWrapperAroundALimitWhileSendingLogsNoCallThatCouldNotBeSent(): void
    {
        $logger = new BufferingLogger();
        $failure = new class('Cut off.', 0, new RateLimitExceededException(300)) extends IncompleteStreamException implements RetryableExceptionInterface {};
        $account = new AccountPlatform($this->account(Account::DEFAULT), $this->answering(static fn (): never => throw $failure), $this->cooldowns, new NativeClock(), $logger, new SendFailures(), new NoPinnedProviderCooldowns(), null);

        try {
            $account->invoke('big', 'Review.');
            self::fail('The call was expected to fail while sending.');
        } catch (IncompleteStreamException $thrown) {
            self::assertSame($failure, $thrown);
        }

        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame(['Account {account} failed.'], array_column($logs, 1));
    }

    public function testAnAccountErrorWhileSendingThatWasAlreadyRecordedLogsNoCallThatCouldNotBeSent(): void
    {
        $logger = new BufferingLogger();
        $failure = new RateLimitExceededException(300);
        $account = new AccountPlatform($this->account(Account::DEFAULT), $this->answering(static fn (): never => throw $failure), $this->cooldowns, new NativeClock(), $logger, new SendFailures(), new NoPinnedProviderCooldowns(), null);
        $this->sendFailing($account);
        $logger->cleanLogs();

        $this->sendFailing($account);

        self::assertSame([], $logger->cleanLogs());
    }

    // The reviewer's probe, because the judging's failure listener logs the exception it gets with its message and its chain.
    public function testARejectionThatEndsTheCallCarriesNoTextOfTheProvider(): void
    {
        $onlyDefault = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $this->answering(static fn (): never => throw new AuthenticationException('invalid key SENTINEL'))], $this->cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());

        try {
            $_ = $onlyDefault->invoke('big', 'Review.');
            self::fail('The only key of the provider is revoked, yet the call answered.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
            self::assertStringNotContainsString('SENTINEL', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testARejectionReadFromTheAnswerThatEndsTheCallCarriesNoTextOfTheProvider(): void
    {
        $answer = $this->accounts($this->answering(static fn (): never => throw new AuthenticationException('revoked')), new FailingWhenRead(new AuthenticationException('invalid key SENTINEL')))->invoke('big', 'Review.');

        try {
            $answer->asText();
            self::fail('Every account rejected its key, yet the answer was read.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
            self::assertStringNotContainsString('SENTINEL', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    // The reviewers' probe, because with a free account left the rejection used to be the provider's own, and the judging's failure listener and Messenger's retry log what they get.
    public function testAKeyRejectedWhenReadWhileAnotherAccountIsFreeCarriesNoTextOfTheProvider(): void
    {
        $answer = $this->accounts(new FailingWhenRead(new AuthenticationException('invalid key SENTINEL')), $this->answering('second'))->invoke('big', 'Review.');

        try {
            $answer->asText();
            self::fail('The default key was rejected when read, yet the answer was read.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(KeyRejected::class, $failure);
            self::assertSame('The key of account "anthropic.default" was rejected.', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }

        self::assertTrue($this->cooldowns->isRejected($this->account(Account::DEFAULT)));
    }

    public function testAKeyRejectedAsTheCallIsSentWhileAnotherAccountIsFreeHandsTheCallToTheNextAccount(): void
    {
        $result = $this->accounts($this->answering(static fn (): never => throw new AuthenticationException('invalid key SENTINEL')), $this->answering('second'))->invoke('big', 'Review.');

        self::assertSame('second', $result->asText());
        self::assertTrue($this->cooldowns->isRejected($this->account(Account::DEFAULT)));
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
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
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
        $onlyDefault = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $this->answering($revoked)], $this->cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());
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

    // The reviewer's probe, because the second call meets no rejection itself and finds the only account cooling down from the first one's.
    public function testASecondCallOnAProviderWhoseOnlyKeyWasRejectedIsTheRejectionAndNotAWait(): void
    {
        $onlyDefault = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $this->answering(static fn (): never => throw new AuthenticationException('revoked'))], $this->cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());
        try {
            $_ = $onlyDefault->invoke('big', 'Review.');
        } catch (AuthenticationException) {
        }

        try {
            $_ = $onlyDefault->invoke('big', 'Review.');
            self::fail('The only key of the provider is revoked, yet the call answered.');
        } catch (Throwable $second) {
            self::assertInstanceOf(EveryKeyRejected::class, $second, 'The only key of the provider is revoked, yet the second call waits.');
        }
    }

    public function testACallOnAProviderWhoseEveryAccountCoolsDownFromARejectionIsTheRejectionWithoutSendingAnything(): void
    {
        $first = new FailingWhenRead(new RuntimeException('never read'));
        $second = new FailingWhenRead(new RuntimeException('never read'));
        $this->cooldowns->coolDownRejected($this->account(Account::DEFAULT));
        $this->cooldowns->coolDownRejected($this->account('team'));

        try {
            $_ = $this->accounts($first, $second)->invoke('big', 'Review.');
            self::fail('Every key of the provider is revoked, yet the call answered.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
            self::assertSame('Every key of provider "anthropic" was rejected.', $failure->getMessage());
        }

        self::assertSame(0, $first->calls + $second->calls);
    }

    public function testACallWhileOneAccountCoolsDownFromARejectionAndTheOtherFromALimitIsAWait(): void
    {
        $this->cooldowns->coolDownRejected($this->account(Account::DEFAULT));
        $this->cooldowns->coolDown($this->account('team'), 120);

        $this->expectException(EveryAccountCoolingDown::class);

        $this->accounts($this->answering('first'), $this->answering('second'))->invoke('big', 'Review.');
    }

    public function testACallWhoseOnlyFreeAccountRejectsItsKeyBesideAnAccountCoolingDownFromItsOwnRejectionIsTheRejection(): void
    {
        $revoked = new AuthenticationException('revoked');
        $this->cooldowns->coolDownRejected($this->account(Account::DEFAULT));

        try {
            $_ = $this->accounts($this->answering('first'), $this->answering(static fn (): never => throw $revoked))->invoke('big', 'Review.');
            self::fail('Every key of the provider is revoked, yet the call answered.');
        } catch (Throwable $failure) {
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
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
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
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
            self::assertInstanceOf(EveryKeyRejected::class, $failure);
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
        $usage = new TokenUsage(
            promptTokens: 10,
            totalTokens: 10,
        );

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

    public function testACallWhoseEveryAccountFailsToReachItsEndpointWhileSendingSaysSoWithoutTheTransportText(): void
    {
        $unreachable = static fn (): never => throw new TransportException('Could not resolve host for "https://localhost/?key=secret".');

        $failure = null;

        try {
            $_ = $this->accounts($this->answering($unreachable), $this->answering($unreachable))->invoke('big', 'Review.');
        } catch (Throwable $thrown) {
            $failure = $thrown;
        }

        self::assertInstanceOf(TransportExceptionInterface::class, $failure);
        self::assertSame([EveryEndpointUnreachable::class, 'Every account of provider "anthropic" could not reach its endpoint.', null], [$failure::class, $failure->getMessage(), $failure->getPrevious()]);
    }

    public function testACallWithOneAccountUnreachableAndTheOtherFailingOtherwiseKeepsTheFailoverError(): void
    {
        $unreachable = static fn (): never => throw new TransportException('Could not resolve host: localhost');
        $broken = static fn (): never => throw new RuntimeException('bad request');

        $this->expectExceptionObject(new PlatformRuntimeException('All platforms failed.'));

        $this->accounts($this->answering($unreachable), $this->answering($broken))->invoke('big', 'Review.');
    }

    // The reviewer's probe: the team account cools down as the call starts and is free again when the failure is typed, so it was never sent to and does not hide that the only account tried was unreachable.
    public function testACallWhoseOnlyTriedAccountFailsToReachItsEndpointSaysSoWhenAnotherAccountFreedUpDuringTheCall(): void
    {
        $teamChecks = 0;
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('secondsUntilFree')->willReturnCallback(static function (Account $account) use (&$teamChecks): int {
            return 'anthropic.team' === $account->key() && 1 === ++$teamChecks ? 60 : 0;
        });
        $unreachable = static fn (): never => throw new TransportException('Could not resolve host: localhost');
        $platform = new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $this->answering($unreachable), 'team' => $this->answering('second')], $cooldowns, new NativeClock(), new NullLogger(), new NoPinnedProviderCooldowns());

        $this->expectException(EveryEndpointUnreachable::class);

        $platform->invoke('big', 'Review.');
    }

    public function testATransportErrorRaisedWhileSendingLeavesTheCallToTheNextAccountLikeAnyFailureWhileSending(): void
    {
        $unreachable = static fn (): never => throw new TransportException('Could not resolve host: localhost');

        $answer = $this->accounts($this->answering($unreachable), $this->answering('second'))->invoke('big', 'Review.');

        self::assertSame('second', $answer->asText());
    }

    public function testTheModelCatalogIsTheFirstAccounts(): void
    {
        $first = $this->answering('first');

        self::assertSame($first->getModelCatalog(), $this->accounts($first, $this->answering('second'))->getModelCatalog());
    }

    public function testAnAnswerToACallSentBeforeALostLoginWasReportedDoesNotReportTheSameErrorAgain(): void
    {
        $clock = new MockClock();
        $ledger = new InMemoryLedger();
        $cooldowns = $this->reporting($clock, $ledger);
        $older = $this->onDefault($this->answering('An answer.'), $cooldowns, $clock)->invoke('big', 'Review.');
        $clock->sleep(1);
        $this->loseTheLogin($cooldowns, $clock);
        $clock->sleep(1);

        $older->getResult();
        $this->loseTheLogin($cooldowns, $clock);

        self::assertCount(1, array_keys($ledger->kinds(), LedgerEventKind::AccountErrorReported, true));
    }

    public function testAnAccountErrorAfterTheAccountAnsweredAgainIsReportedAgain(): void
    {
        $clock = new MockClock();
        $ledger = new InMemoryLedger();
        $cooldowns = $this->reporting($clock, $ledger);
        $this->loseTheLogin($cooldowns, $clock);
        $clock->sleep(1);

        $this->onDefault($this->answering('An answer.'), $cooldowns, $clock)->invoke('big', 'Review.')->getResult();
        $clock->sleep(1);
        $this->loseTheLogin($cooldowns, $clock);

        self::assertCount(2, array_keys($ledger->kinds(), LedgerEventKind::AccountErrorReported, true));
    }

    public function testAStreamReadToItsEndAfterTheReportLetsTheNextAccountErrorBeReported(): void
    {
        $clock = new MockClock();
        $ledger = new InMemoryLedger();
        $cooldowns = $this->reporting($clock, $ledger);
        $this->loseTheLogin($cooldowns, $clock);
        $clock->sleep(1);

        $answer = $this->onDefault($this->extracting(new TokenUsage(), static fn (): StreamResult => new StreamResult((static fn (): Generator => yield new TextDelta('An answer.'))())), $cooldowns, $clock)->invoke('big', 'Review.');
        iterator_to_array($answer->asStream());
        $clock->sleep(1);
        $this->loseTheLogin($cooldowns, $clock);

        self::assertCount(2, array_keys($ledger->kinds(), LedgerEventKind::AccountErrorReported, true));
    }

    public function testAnAnswerThatFailsAfterTheReportKeepsTheAccountErrorReported(): void
    {
        $clock = new MockClock();
        $ledger = new InMemoryLedger();
        $cooldowns = $this->reporting($clock, $ledger);
        $this->loseTheLogin($cooldowns, $clock);
        $clock->sleep(1);

        $this->read($this->onDefault(new FailingWhenRead(new RuntimeException('Not an answer.')), $cooldowns, $clock)->invoke('big', 'Review.'));
        $clock->sleep(1);
        $this->loseTheLogin($cooldowns, $clock);

        self::assertCount(1, array_keys($ledger->kinds(), LedgerEventKind::AccountErrorReported, true));
    }

    public function testALimitThatArrivesBeforeTheFirstDeltaOfAStreamCoolsTheAccountDownOnce(): void
    {
        $cooldowns = $this->createMock(AccountCooldowns::class);
        $cooldowns->expects(self::once())->method('coolDown')->with($this->account(Account::DEFAULT), 300);
        $failures = new SendFailures();

        $this->readStream($this->streamed(static function (): Generator {
            yield from [];

            throw new RateLimitExceededException(300);
        }, $cooldowns, $failures));

        self::assertFalse($failures->wasRejected($this->account(Account::DEFAULT)));
    }

    public function testALimitThatArrivesAfterTheFirstDeltaOfAStreamCoolsTheAccountDownOnceWithTheProvidersRetryAfter(): void
    {
        $cooldowns = $this->createMock(AccountCooldowns::class);
        $cooldowns->expects(self::once())->method('coolDown')->with($this->account(Account::DEFAULT), 300);

        $this->readStream($this->streamed(static function (): Generator {
            yield new TextDelta('Looks');

            // Wrapped like this because StreamedAnswer does so once the answer has started.
            throw new IncompleteStreamException('Cut off.', 0, new RateLimitExceededException(300));
        }, $cooldowns, new SendFailures()));
    }

    public function testAnAuthenticationFailureMidStreamMarksTheAccountAsRejected(): void
    {
        $cooldowns = $this->createMock(AccountCooldowns::class);
        $cooldowns->expects(self::once())->method('coolDownRejected')->with($this->account(Account::DEFAULT));
        $failures = new SendFailures();

        $this->readStream($this->streamed(static function (): Generator {
            yield new TextDelta('Looks');

            throw new AuthenticationException('revoked');
        }, $cooldowns, $failures));

        self::assertTrue($failures->wasRejected($this->account(Account::DEFAULT)));
    }

    public function testAStreamCutWithoutACauseLeavesTheAccountFree(): void
    {
        $cooldowns = $this->createMock(AccountCooldowns::class);
        $cooldowns->expects(self::never())->method('coolDown');

        $this->readStream($this->streamed(static function (): Generator {
            yield new TextDelta('Looks');

            throw new IncompleteStreamException('Cut off.');
        }, $cooldowns, new SendFailures()));
    }

    public function testAFailureRecordedTwiceForOneAccountIsNewOnlyTheFirstTime(): void
    {
        $failures = new SendFailures();
        $failure = new RateLimitExceededException(300);

        self::assertTrue($failures->record($this->account(Account::DEFAULT), $failure));
        self::assertFalse($failures->record($this->account(Account::DEFAULT), $failure));
    }

    public function testAFailureAlreadyRecordedForOneAccountIsNewForAnotherAccount(): void
    {
        $failures = new SendFailures();
        $failure = new RateLimitExceededException(300);

        self::assertTrue($failures->record($this->account(Account::DEFAULT), $failure));
        self::assertTrue($failures->record($this->account('team'), $failure));
    }

    private function readStream(DeferredResult $result): void
    {
        $failure = null;

        try {
            foreach ($result->asStream() as $delta) {
                self::assertInstanceOf(TextDelta::class, $delta);
            }
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNotNull($failure, 'The stream was expected to fail when read.');
    }

    /**
     * @param Closure(): Generator<TextDelta> $deltas
     */
    private function streamed(Closure $deltas, AccountCooldowns $cooldowns, SendFailures $failures): DeferredResult
    {
        $platform = $this->extracting(new TokenUsage(), static fn (): StreamResult => new StreamResult($deltas()));
        $account = new AccountPlatform($this->account(Account::DEFAULT), $platform, $cooldowns, new NativeClock(), new NullLogger(), $failures, new NoPinnedProviderCooldowns(), null);

        return $account->invoke('big', 'Review.');
    }

    // The cooldowns read the same mocked clock as the calls, so the order of a send and a report is the one the test forces.
    private function reporting(ClockInterface $clock, InMemoryLedger $ledger): AccountCooldownWindows
    {
        return new AccountCooldownWindows(new ReadingsInMemory(new NativeClock()), new ArrayAdapter(), new LockFactory(new InMemoryStore()), $clock, $ledger, new NullLogger(), StoredSerializerFactory::create(), [Provider::Anthropic->value => [Account::DEFAULT]]);
    }

    private function onDefault(PlatformInterface $platform, AccountCooldowns $cooldowns, ClockInterface $clock): AccountPlatform
    {
        return new AccountPlatform($this->account(Account::DEFAULT), $platform, $cooldowns, $clock, new NullLogger(), new SendFailures(), new NoPinnedProviderCooldowns(), null);
    }

    private function sendFailing(AccountPlatform $account): void
    {
        try {
            $account->invoke('big', 'Review.');
            self::fail('The call was expected to fail while sending.');
        } catch (RateLimitExceededException) {
        }
    }

    private function loseTheLogin(AccountCooldowns $cooldowns, ClockInterface $clock): void
    {
        try {
            $this->onDefault($this->answering(static fn (): never => throw CodexAccountFailure::LoginLost->exception()), $cooldowns, $clock)->invoke('big', 'Review.');
            self::fail('The lost login was expected to fail the call.');
        } catch (AuthenticationException) {
        }
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
        return new AccountFailoverPlatform(Provider::Anthropic, [Account::DEFAULT => $default, 'team' => $team], $this->cooldowns, new NativeClock(), $logger ?? new NullLogger(), new NoPinnedProviderCooldowns());
    }

    private function account(string $name): Account
    {
        return new Account(Provider::Anthropic, $name);
    }
}
