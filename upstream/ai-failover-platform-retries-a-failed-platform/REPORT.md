# [Failover Platform] A failed platform is called again on the next call, whatever its rate limiter says

Draft, not posted. Versions: `symfony/ai-failover-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.10, PHP 8.5.12.

## What happens

`FailoverPlatform::do()` records a failed platform in `$failedPlatforms` and consumes a token of its limiter, but on the next call it invokes every platform in order again: the limiter's answer only decides whether the platform is removed from `$failedPlatforms`, never whether it is skipped, and `$failedPlatforms` is never read otherwise. A platform that is down is therefore called first on every call.

The limiter is also created with `$platform::class` as its key, so two platforms of one bridge (two accounts of one provider, both `Symfony\AI\Platform\Platform`) share one limiter: a failure of the first uses up the second's tokens.

`reproduce.php` gives the platform a failing and a working platform of one class and a limiter of one token per hour, calls it twice and prints:

```
the failing platform was called 2 times, the working one 2 times
a failed platform is called again on the next call
```

## Expected

While a failed platform's limiter has no room, the next call skips it and goes to the next platform, so the failing platform is called once; and each platform has a limiter of its own (for example keyed by its position or a name), not one per class.

## Effect in Intakt

`src/Core/Ai/Infrastructure/AccountFailoverPlatform.php` picks the accounts that are not cooling down itself and builds a `FailoverPlatform` over only those for each call, with a limiter that never limits.
