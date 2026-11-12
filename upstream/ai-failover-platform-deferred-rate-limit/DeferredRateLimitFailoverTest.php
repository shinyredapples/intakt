<?php

declare(strict_types=1);

namespace Intakt\Upstream\FailoverPlatformDeferredRateLimit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class KeyRecordingLimiterFactory implements RateLimiterFactoryInterface
{
    /** @var list<string|null> */
    public array $keys = [];

    private readonly RateLimiterFactory $limiters;

    public function __construct()
    {
        $this->limiters = new RateLimiterFactory(['id' => 'failover', 'policy' => 'no_limit'], new InMemoryStorage());
    }

    public function create(?string $key = null): LimiterInterface
    {
        $this->keys[] = $key;

        return $this->limiters->create($key);
    }
}

// Two accounts of one provider are two platforms of one class. The 429 of the first account is replayed from a redacted cassette in cassettes/, so it surfaces as it does on the wire: when the result is read.
#[CoversNothing]
final class DeferredRateLimitFailoverTest extends TestCase
{
    public function testARateLimitThatSurfacesWhenTheResultIsReadFailsOverToTheSecondPlatform(): void
    {
        $platform = new FailoverPlatform([$this->account('first-account-rate-limited'), $this->account('second-account-answers')], new KeyRecordingLimiterFactory());

        $result = $platform->invoke('local-model', new MessageBag(Message::ofUser('Review the change.')));

        self::assertSame('answer of the second account', $result->asText());
    }

    // The control case: the same 429 raised while invoking fails over as documented.
    public function testARateLimitRaisedWhileInvokingFailsOverToTheSecondPlatform(): void
    {
        $platform = new FailoverPlatform([$this->rateLimitedOnInvoke(), MockPlatformFactory::createPlatform('answer of the second account')], new KeyRecordingLimiterFactory());

        $result = $platform->invoke('local-model', 'Review the change.');

        self::assertSame('answer of the second account', $result->asText());
    }

    public function testTwoPlatformsOfOneClassHaveRateLimitKeysOfTheirOwn(): void
    {
        $limiters = new KeyRecordingLimiterFactory();
        $platform = new FailoverPlatform([$this->rateLimitedOnInvoke(), MockPlatformFactory::createPlatform('answer of the second account')], $limiters);

        $platform->invoke('local-model', 'Review the change.');

        self::assertCount(2, $limiters->keys);
        self::assertNotSame($limiters->keys[0], $limiters->keys[1], 'Both platforms share the rate-limit key '.$limiters->keys[0]);
    }

    private function account(string $cassette): PlatformInterface
    {
        $http = new CassetteHttpClient(new HttpCassette(__DIR__.'/cassettes/'.$cassette.'.json'), record: false);

        return GenericFactory::createPlatform('https://localhost', 'probe-key', $http);
    }

    private function rateLimitedOnInvoke(): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static fn (): never => throw new RateLimitExceededException(30, 'Rate limit reached for requests'));
    }
}
