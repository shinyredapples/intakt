<?php

declare(strict_types=1);

namespace Intakt\Upstream\FailoverPlatformDeferredRateLimit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversNothing]
final class GenericRetryAfterHeaderTest extends TestCase
{
    public function testARateLimitedCompletionCarriesTheRetryAfterHeaderOfItsResponse(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"rate limited","type":"rate_limit"}}', [
            'http_code' => 429,
            'response_headers' => ['content-type: application/json', 'retry-after: 137'],
        ]));
        $platform = Factory::createPlatform('https://localhost', 'probe-key', $http);

        try {
            $platform->invoke('local-model', new MessageBag(Message::ofUser('Review the change.')))->asText();
            self::fail('The 429 was expected to throw.');
        } catch (RateLimitExceededException $limited) {
            self::assertSame(137, $limited->getRetryAfter(), 'The retry-after header of the 429 does not reach the exception.');
        }
    }
}
