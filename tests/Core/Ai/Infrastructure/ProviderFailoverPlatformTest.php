<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryProviderOut;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ProviderWait;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\TierRequest;
use Intakt\Core\Ai\Infrastructure\AccountCooldownWindows;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\FailoverTrail;
use Intakt\Core\Ai\Infrastructure\ProviderCandidate;
use Intakt\Core\Ai\Infrastructure\ProviderFailoverPlatform;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

// Over the real account failover and cooldowns, on the native clock because the cooldowns are RateLimiter windows.
#[CoversClass(ProviderFailoverPlatform::class)]
#[CoversClass(ProviderCandidate::class)]
#[CoversClass(FailoverTrail::class)]
#[CoversClass(EveryProviderOut::class)]
#[UsesClass(ProviderWait::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(AccountFailoverPlatform::class)]
#[UsesClass(AccountPlatform::class)]
#[UsesClass(AccountCooldownWindows::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierRequest::class)]
final class ProviderFailoverPlatformTest extends TestCase
{
    private AccountCooldownWindows $cooldowns;

    protected function setUp(): void
    {
        $this->cooldowns = new AccountCooldownWindows(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new NativeClock(), new InMemoryLedger(), new NullLogger(), [
            Provider::Anthropic->value => [Account::DEFAULT],
            Provider::OpenAi->value => [Account::DEFAULT],
            Provider::OpenAiCompatible->value => [Account::DEFAULT],
        ]);
    }

    public function testATierWithAFreeProviderIsAnsweredByItAndNoSubstituteIsMarked(): void
    {
        $result = $this->failover()->invoke($this->own(), [$this->substitute(Provider::OpenAi)], new TierRequest('Review.'));

        self::assertSame('anthropic answered as big', $result->asText());
        self::assertNull($result->getMetadata()->get(Provider::SUBSTITUTE_METADATA));
    }

    public function testATierWithNoFreeAccountOnItsProviderIsAnsweredByItsFirstSubstituteAndTheSwitchIsRecorded(): void
    {
        $this->coolDown(Provider::Anthropic);
        $logger = new BufferingLogger();

        $result = $this->failover($logger)->invoke($this->own(), [$this->substitute(Provider::OpenAi), $this->substitute(Provider::OpenAiCompatible)], new TierRequest('Review.'));

        self::assertSame('openai answered as sub-openai', $result->asText());
        self::assertSame('openai', $result->getMetadata()->get(Provider::SUBSTITUTE_METADATA));
        self::assertSame([['info', 'Provider {own} is out, so the call goes to the substitute {substitute}.', ['own' => 'anthropic', 'substitute' => 'openai']]], $this->logged($logger));
    }

    public function testAProviderWhoseKeyIsRevokedAsTheCallIsSentHandsItToTheSubstituteAndCoolsItsAccountDown(): void
    {
        $accounts = $this->accountsOf(Provider::Anthropic, MockPlatformFactory::createPlatform(static fn (): never => throw new AuthenticationException('revoked')));

        $result = $this->failover(platforms: [Provider::Anthropic->value => $accounts])->invoke($this->own(), [$this->substitute(Provider::OpenAi)], new TierRequest('Review.'));

        self::assertSame('openai', $result->getMetadata()->get(Provider::SUBSTITUTE_METADATA));
        self::assertTrue($this->cooldowns->isCoolingDown(new Account(Provider::Anthropic, Account::DEFAULT)));
    }

    public function testATierWithoutSubstitutesReportsItsProviderAsOutAndDoesNotSubstitute(): void
    {
        $this->coolDown(Provider::Anthropic);

        $this->expectException(EveryAccountCoolingDown::class);

        $_ = $this->failover()->invoke($this->own(), [], new TierRequest('Review.'));
    }

    public function testASubstituteWhoseEveryAccountIsCoolingDownIsSkippedForTheNextOne(): void
    {
        $this->coolDown(Provider::Anthropic);
        $this->coolDown(Provider::OpenAi);

        $result = $this->failover()->invoke($this->own(), [$this->substitute(Provider::OpenAi), $this->substitute(Provider::OpenAiCompatible)], new TierRequest('Review.'));

        self::assertSame('openai_compatible', $result->getMetadata()->get(Provider::SUBSTITUTE_METADATA));
    }

    public function testWhenEveryProviderOfATierIsOutTheFailureNamesEachProviderAndItsWait(): void
    {
        $this->coolDown(Provider::Anthropic);
        $this->coolDown(Provider::OpenAi);

        try {
            $_ = $this->failover()->invoke($this->own(), [$this->substitute(Provider::OpenAi)], new TierRequest('Review.'));
            self::fail('No provider was free.');
        } catch (EveryProviderOut $out) {
            self::assertSame([Provider::Anthropic, Provider::OpenAi], array_map(static fn (ProviderWait $wait): Provider => $wait->provider, $out->waits));
            self::assertStringStartsWith('Every provider of the tier is out: anthropic for ', $out->getMessage());
            self::assertStringContainsString(', openai for ', $out->getMessage());
        }
    }

    public function testAFailureOfTheCallItselfIsNeverHandedToASubstitute(): void
    {
        $badRequest = new RuntimeException('the diff breaks the request');
        $platforms = [Provider::Anthropic->value => MockPlatformFactory::createPlatform(static fn (): never => throw $badRequest)];

        try {
            $_ = $this->failover(platforms: $platforms)->invoke($this->own(), [$this->substitute(Provider::OpenAi)], new TierRequest('Review.'));
            self::fail('The failure of the call was swallowed.');
        } catch (RuntimeException $failure) {
            self::assertSame('All platforms failed.', $failure->getMessage());
        }

        self::assertFalse($this->cooldowns->isCoolingDown(new Account(Provider::OpenAi, Account::DEFAULT)));
    }

    private function own(): ResolvedModel
    {
        return new ResolvedModel(Provider::Anthropic, 'big');
    }

    private function substitute(Provider $provider): ResolvedModel
    {
        return new ResolvedModel($provider, 'sub-'.$provider->value);
    }

    private function coolDown(Provider $provider): void
    {
        $this->cooldowns->coolDown(new Account($provider, Account::DEFAULT), 300);
    }

    /**
     * @param array<string, PlatformInterface>|null $platforms
     */
    private function failover(?BufferingLogger $logger = null, ?array $platforms = null): ProviderFailoverPlatform
    {
        $platforms ??= [];
        $services = [];

        foreach ([Provider::Anthropic, Provider::OpenAi, Provider::OpenAiCompatible] as $provider) {
            $services[$provider->value] = fn (): PlatformInterface => $this->accountsOf($provider, $platforms[$provider->value] ?? $this->answering($provider));
        }

        return new ProviderFailoverPlatform(new ServiceLocator($services), $this->cooldowns, new NativeClock(), $logger ?? new NullLogger());
    }

    private function accountsOf(Provider $provider, PlatformInterface $platform): PlatformInterface
    {
        return new AccountFailoverPlatform($provider, [Account::DEFAULT => $platform], $this->cooldowns, new NativeClock(), new NullLogger());
    }

    private function answering(Provider $provider): PlatformInterface
    {
        $answer = static fn (Model $model): string => sprintf('%s answered as %s', $provider->value, $model->getName());

        return MockPlatformFactory::createPlatform($answer, name: $provider->value);
    }

    /**
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function logged(BufferingLogger $logger): array
    {
        return array_map(static fn (array $entry): array => [$entry[0], $entry[1], $entry[2]], $logger->cleanLogs());
    }
}
