Repository: symfony/ai. Template: Bug Report (label `Bug`). Not posted: the owner approves this text first.

Title: [Platform][Failover] A failed platform is called again on the next call, and platforms of one class share one limiter

### Description

`FailoverPlatform::do()` puts a failed platform into `$failedPlatforms` and consumes a token of its limiter, but the next call tries every platform in order again: the limiter's answer only decides whether the platform leaves `$failedPlatforms`, never whether it is skipped, and `$failedPlatforms` is read nowhere else. A platform that is down is called first on every call.

Separately, the limiter is created with `$platform::class` as its key. The OpenAi, Ollama and Anthropic factories all return `Symfony\AI\Platform\Platform`, so every platform shares one limiter (the documented Ollama-to-OpenAI example included), and successful calls consume a token of it too.

### Versions

`symfony/ai-failover-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.10, PHP 8.5.12. Still the same on `main` (6747a33).

### How to reproduce

`FailingPlatform` throws on `invoke()`, `WorkingPlatform` returns a result; both implement `PlatformInterface` and count their calls. They are two classes, so each has a limiter of its own and the retry shows apart from the shared key:

```php
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

$limiter = new RateLimiterFactory(['id' => 'failover', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
$failing = new FailingPlatform();
$working = new WorkingPlatform();
$platform = new FailoverPlatform([$failing, $working], $limiter);

$platform->invoke('model', 'first call');
$platform->invoke('model', 'second call');

echo $failing->calls, ' ', $working->calls, PHP_EOL;
```

For the shared key, wrap the factory in a `RateLimiterFactoryInterface` that records the keys passed to `create()`, and invoke a `FailoverPlatform` over `[new FailingPlatform(), new FailingPlatform(), new WorkingPlatform()]` once.

### Actual behavior

```
2 2
```

The failing platform is called on both calls. The recorded keys are `FailingPlatform, FailingPlatform, WorkingPlatform`: both failing platforms use one limiter.

### Expected behavior

```
1 2
```

A platform in `$failedPlatforms` is skipped until its own limiter allows a retry, and each platform has a limiter of its own (keyed, for example, by its position or a name), not one per class.

### Possible solution

Check `$failedPlatforms` before calling a platform and skip it while its limiter has no room; consume a token only on a failure; key the limiter by the platform's position in the list or by a name given with it. Open pull requests #1808 and #1864 change the constructor and the platform list of this class, so the maintainers may prefer to fold the fix into one of them.

### Additional context

#1953 plans a `FailoverProvider` to replace this class; it should not carry these two bugs over.
