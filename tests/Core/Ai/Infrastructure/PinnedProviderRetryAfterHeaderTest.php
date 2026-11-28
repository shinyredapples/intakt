<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\PinnedProvider;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Infrastructure\AccountFailoverPlatform;
use Intakt\Core\Ai\Infrastructure\AccountPlatform;
use Intakt\Core\Ai\Infrastructure\ModelWaits;
use Intakt\Core\Ai\Infrastructure\PinnedProviderCooldownWindows;
use Intakt\Core\Ai\Infrastructure\ProjectModelCatalog;
use Intakt\Core\Ai\Infrastructure\SendFailures;
use Intakt\Core\Ai\Infrastructure\TypedRejectionConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

// A real 429 through Symfony AI's generic bridge over a mock HTTP client, because the bridge drops the response's Retry-After when it builds its exception and a scripted exception with a preset wait would hide that.
#[CoversClass(AccountPlatform::class)]
#[CoversClass(AccountFailoverPlatform::class)]
#[UsesClass(PinnedProviderCooldownWindows::class)]
#[UsesClass(ModelWaits::class)]
#[UsesClass(PinnedProvider::class)]
#[UsesClass(ProjectModelCatalog::class)]
#[UsesClass(EveryAccountCoolingDown::class)]
#[UsesClass(SendFailures::class)]
#[UsesClass(TypedRejectionConverter::class)]
#[UsesClass(Account::class)]
#[UsesClass(Provider::class)]
final class PinnedProviderRetryAfterHeaderTest extends TestCase
{
    private const string PINNED = 'owner/model:router';

    private const Provider ROUTER = Provider::OpenAiCompatible;

    private MockClock $clock;

    private PinnedProviderCooldownWindows $pins;

    private AccountWindowsOnAClock $accounts;

    #[DataProvider('retryAfterHeaders')]
    public function testARouter429CoolsThePinAndTheAccountForTheRetryAfterHeaderAndTheNextCallIsSkippedWithoutARequest(string $header, int $seconds, ?string $now = null): void
    {
        if (null !== $now) {
            $this->clock->modify($now);
        }

        $http = $this->limited(['retry-after: '.$header]);
        $platform = $this->platform($http);

        $this->failureOf(fn (): string => $platform->invoke(self::PINNED, $this->messages())->asText());
        $next = $this->failureOf(fn (): object => $platform->invoke(self::PINNED, $this->messages()));

        self::assertInstanceOf(EveryAccountCoolingDown::class, $next);
        self::assertSame([$seconds, $seconds, $seconds, 1], [$this->pins->secondsUntilFree($this->pin()), $this->accounts->secondsUntilFree($this->account()), $next->secondsUntilAnAccountIsFree, $http->getRequestsCount()]);
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2?: string}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'delta seconds' => ['137', 137];
        yield 'delta seconds with leading zeros' => ['000000000137', 137];
        yield 'delta seconds beyond a day, capped' => ['1000000000', 86400];
        yield 'delta seconds too long for an integer, capped' => ['99999999999999999999999', 86400];
        yield 'zero' => ['0', 1];
        yield 'an IMF-fixdate' => ['Thu, 19 Nov 2026 12:02:00 GMT', 120];
        yield 'an RFC 850 date' => ['Thursday, 19-Nov-26 12:02:00 GMT', 120];
        yield 'an asctime date' => ['Thu Nov 19 12:02:00 2026', 120];
        yield 'an asctime date with a padded one-digit day' => ['Mon Nov  9 12:02:00 2026', 120, '2026-11-09T12:00:00.400000+00:00'];
        yield 'a date beyond a day, capped' => ['Sat, 21 Nov 2026 12:00:00 GMT', 86400];
        yield 'a past date' => ['Thu, 19 Nov 2026 11:00:00 GMT', 1];
    }

    #[DataProvider('unreadableRetryAfters')]
    public function testARouter429WithoutAReadableRetryAfterHeaderCoolsThePinForTheDefaultMinute(string $header): void
    {
        $platform = $this->platform($this->limited('' === $header ? [] : ['retry-after: '.$header]));

        $failure = $this->failureOf(fn (): string => $platform->invoke(self::PINNED, $this->messages())->asText());

        self::assertInstanceOf(RateLimitExceededException::class, $failure);
        self::assertSame(60, $this->pins->secondsUntilFree($this->pin()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableRetryAfters(): iterable
    {
        yield 'no header' => [''];
        yield 'a word' => ['soon'];
        yield 'a negative number' => ['-5'];
        yield 'a date PHP would normalize' => ['Thu, 19 Nov 2026 12:99:00 GMT'];
        yield 'a date with the wrong weekday' => ['Fri, 19 Nov 2026 12:02:00 GMT'];
        yield 'a date with an embedded NUL' => ["Thu, 19 Nov\0 2026 12:02:00 GMT"];
        yield 'a date with a non-ASCII byte' => ["Thu, 19 Nov\u{00A0}2026 12:02:00 GMT"];
        yield 'hour 24' => ['Thu, 19 Nov 2026 24:00:00 GMT'];
        yield 'second 61' => ['Thu, 19 Nov 2026 12:02:61 GMT'];
        yield 'February 31' => ['Tue, 31 Feb 2026 12:02:00 GMT'];
        yield 'a lowercase GMT' => ['Thu, 19 Nov 2026 12:02:00 gmt'];
        yield 'delta seconds with a trailing NUL' => ["137\0"];
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19T12:00:00.400000+00:00');
        $this->pins = new PinnedProviderCooldownWindows(new ArrayAdapter(), $this->clock, new LockFactory(new InMemoryStore()));
        $this->accounts = new AccountWindowsOnAClock($this->clock, [self::ROUTER->value => [Account::DEFAULT]]);
    }

    /**
     * @param list<string> $headers
     */
    private function limited(array $headers): MockHttpClient
    {
        return new MockHttpClient(new MockResponse('{"error":{"message":"rate limited","type":"rate_limit"}}', [
            'http_code' => 429,
            'response_headers' => ['content-type: application/json', ...$headers],
        ]));
    }

    private function platform(MockHttpClient $http): AccountFailoverPlatform
    {
        $models = new ProjectModelCatalog(['demo' => ['tiers' => ['strong' => ['provider' => self::ROUTER->value, 'model' => self::PINNED]]]]);
        $bridge = Factory::createPlatform(
            'https://router.example',
            'test',
            httpClient: $http,
            modelCatalog: new InMemoryModelCatalog($models->models(self::ROUTER)),
        );

        return new AccountFailoverPlatform(self::ROUTER, [Account::DEFAULT => $bridge], $this->accounts, $this->clock, new NullLogger(), $this->pins, new OwnersOrderRanking());
    }

    private function messages(): MessageBag
    {
        return new MessageBag(Message::ofUser('Review.'));
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

    private function pin(): PinnedProvider
    {
        return new PinnedProvider($this->account(), 'router');
    }

    private function account(): Account
    {
        return new Account(self::ROUTER, Account::DEFAULT);
    }
}
