Repository: symfony/ai. Template: Bug Report (label `Bug`). Not posted: the owner approves this text first.

Title: [Platform][Failover] A rate limit that surfaces when the result is read never reaches the next platform

### Description

`FailoverPlatform` tries the next platform only when `invoke()` throws. The bridges' HTTP clients are lazy: `invoke()` returns a `DeferredResult` without reading the response, and the result converter raises `RateLimitExceededException` for a 429 (or `ServerException` for a 5xx) only when the result is read. By then `FailoverPlatform` has already returned the first platform's result, so the second platform is never asked and the caller gets the 429.

### Versions

`symfony/ai-failover-platform`, `symfony/ai-platform` and `symfony/ai-generic-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.11, PHP 8.5.12. The same on `main` (e2b5e84).

### How to reproduce

```php
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

$limited = new MockHttpClient(new MockResponse('{"error":{"message":"Rate limit reached"}}', [
    'http_code' => 429,
    'response_headers' => ['content-type: application/json', 'retry-after: 30'],
]));
$answers = new MockHttpClient(new MockResponse(
    '{"choices":[{"index":0,"message":{"role":"assistant","content":"second answer"},"finish_reason":"stop"}]}',
    ['response_headers' => ['content-type: application/json']],
));

$platform = new FailoverPlatform([
    Factory::createPlatform('https://localhost', 'key', $limited),
    Factory::createPlatform('https://localhost', 'key', $answers),
], new RateLimiterFactory(['id' => 'failover', 'policy' => 'no_limit'], new InMemoryStorage()));

echo $platform->invoke('local-model', new MessageBag(Message::ofUser('Hello')))->asText();
```

The same 429 thrown inside `invoke()` of the first platform fails over and prints `second answer`.

### Actual behavior

`asText()` throws `RateLimitExceededException: Rate limit exceeded. Rate limit reached`; the second platform is never called.

### Expected behavior

`second answer`: a provider error reported through the `DeferredResult` fails over like one thrown by `invoke()`. Reading the first result inside `FailoverPlatform` would lose the laziness of streams, so one way is a `DeferredResult` that asks the next platform when the conversion of the first one fails.

Related: #2890 fixes the retry of a failed platform and the shared limiter key in the same loop, not this. #1953 plans a `FailoverProvider` that would need the same rule.
