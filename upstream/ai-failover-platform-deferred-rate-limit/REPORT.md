# [Failover Platform] A 429 that surfaces when the result is read never reaches the next platform

Draft, not posted. Versions: `symfony/ai-failover-platform` v1.0.0-BETA1, `symfony/ai-platform` v1.0.0-BETA1, `symfony/ai-generic-platform` v1.0.0-BETA1, `symfony/rate-limiter` v8.1.11, PHP 8.5.12.

## What happens

`FailoverPlatform::do()` moves on to the next platform only when `invoke()` throws. A bridge's HTTP client is lazy: `invoke()` returns a `DeferredResult` without looking at the response, and the bridge's result converter raises `RateLimitExceededException` for a 429 only when the result is read (`asText()`, `getResult()`). By then `FailoverPlatform` has returned the first platform's result, so the second platform is never asked and the caller gets the 429.

`DeferredRateLimitFailoverTest.php` runs two accounts of one provider as two Generic platforms over `FailoverPlatform`. The first account's 429 (with `retry-after: 30`) and the second account's answer are replayed from the redacted cassettes in `cassettes/`, written through `CassetteHttpClient` from scripted HTTP answers, not from a live provider. Run alone (`vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php upstream/ai-failover-platform-deferred-rate-limit`):

```
 ✘ A rate limit that surfaces when the result is read fails over to the second platform
   │ Symfony\AI\Platform\Exception\RateLimitExceededException: Rate limit exceeded. Rate limit reached for requests
 ✔ A rate limit raised while invoking fails over to the second platform
 ✘ Two platforms of one class have rate limit keys of their own
   │ Both platforms share the rate-limit key Symfony\AI\Platform\Platform
```

The second test is the control: the same 429 raised inside `invoke()` (a `MockPlatformFactory` platform whose `ScriptedResponse` closure throws) fails over as documented. The third test is the shared limiter key of two platforms of one class, already reported in `upstream/ai-failover-platform-retries-a-failed-platform/` and fixed by the open symfony/ai#2890; it stays here because two accounts of one provider are exactly that case.

## Expected

A provider error that a platform reports through its `DeferredResult` fails over like one thrown by `invoke()`: the second platform answers and `asText()` returns its answer. A failover that reads the first result itself loses the laziness of streaming and of the caller's own error handling, so how to fail over late is a design choice of the failover (for example a `DeferredResult` that tries the next platform when the conversion of the first fails, before any delta reached the caller).

## Effect in Intakt

`src/Core/Ai/Infrastructure/AccountPlatform.php` hangs the account's cooldown on the result's `onError()`, so the limited account is skipped on the next call, and the review is started again on the other account (`docs/architecture/ai-providers.md`, `docs/architecture/vendor.md`). The call that met the 429 still fails; Intakt does not rely on `FailoverPlatform` for it.

## Upstream status

- **Pinned versions:** still fails (two of three tests, as above).
- **Development branch:** still fails, the same two tests. Run in a scratch copy of `vendor/` with `src/platform`, `src/agent` and the Failover and Generic bridges of `symfony/ai` `main` e2b5e8476ead swapped in. At the head of the open symfony/ai#2890 (2c2bf18b35b0, `FailoverPlatform.php` only) the shared key test passes and the 429 read from the result still fails.
- **Checked:** 2026-11-12 against `symfony/ai` `main` e2b5e8476ead and the newest release v1.0.0-BETA1 (f620d0fc6790); duplicate search in `symfony/ai` issues and pull requests, open and closed, with the terms `FailoverPlatform`, `failover DeferredResult`, `failover 429`, `failover rate limit`. Found: #2890 (open pull request, the retry and the shared key; does not read the result), #1953 (open issue, a `FailoverProvider` to replace this class; its sketch fails over only on errors thrown by `invoke()`), #1391 (closed issue, a 403 not passed to the next platform, thrown on invoke), #1808 and #1864 (open pull requests, model override and model failover). None covers an error that surfaces when the result is read.
- **Form:** an issue. `CONTRIBUTING.md` of `symfony/ai` sends bug reports to its issue tracker, and every pull request targets `main` (no maintenance branches).
- **Fix pull request:** `issue only`. How to fail over after `invoke()` returned is a design choice (see Expected), and #2890 rewrites the same loop in `do()`, so a fix now would conflict with it.
- **Decision:** `ready to file` as a new issue; the text is `ISSUE.md`. It names #2890 and #1953 in one line each.
